<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Scope\AiScope;
use App\Comptabilite\CourtierEcritureComptableService;
use App\Comptabilite\PlanComptable;
use App\Entity\ChargementPourPrime;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Service\Note\EmissionDeNote;
use App\Entity\Client;
use App\Service\Note\NoteNonValidable;
use App\Service\Workspace\OrigineEcriture;
use App\Service\Workspace\WorkspaceMutationService;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * UNE NOTE ÉMISE NE SE RECALCULE PLUS (règle OHADA, décision de Serge du 2026-10-15).
 *
 * Ses montants — par ligne et au total, HT, taxe, TTC — se figent à la validation. Une
 * prime, un taux, une part d'échéance ou un taux de taxe modifiés après coup ne changent
 * ni son montant, ni son écriture au journal ; un brouillon, lui, suit. Avant : le montant
 * n'était stocké nulle part, et une correction de prime réécrivait la facture envoyée.
 *
 * Et trois défauts corrigés AVANT de figer — on ne fige pas un montant faux pour dix ans :
 * l'avoir ventile HT et taxe (son « HT » valait le TTC) ; l'avoir à l'autorité fiscale
 * respecte l'exonération ; sans utilisateur connecté, la taxe se calcule quand même.
 */
class FigeageDesNotesTest extends KernelTestCase
{
    use AffaireFacturableTrait;

    private const EMAIL = 'phpunit-figeage@test.local';
    private const CABINET = 'PHPUnit Figeage SARL';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $this->effacerLesCabinets([self::CABINET], [self::EMAIL]);
    }

    private function valider(int $id): Note
    {
        static::getContainer()->get(EmissionDeNote::class)->valider($this->note($id));

        return $this->note($id);
    }

    /** @return array{ht: float, ttc: float} ce que les écrans liraient aujourd'hui */
    private function montants(int $id): array
    {
        $helper = static::getContainer()->get(IndicatorCalculationHelper::class);
        $helper->reset();
        $note = $this->note($id);

        return ['ht' => round($helper->getNoteMontantHT($note), 2), 'ttc' => round($helper->getNoteMontantTotal($note), 2)];
    }

    /** Tout ce qui fait le montant d'une commission change : prime, taux, part, taxe. */
    private function toutModifier(array $c): void
    {
        $em = $this->em();
        $em->find(ChargementPourPrime::class, $c['ligne'])->setMontantFlatExceptionel(20000.0);
        $em->find(TypeRevenu::class, $c['type'])->setPourcentage(20.0);
        $em->find(Tranche::class, $c['tranche'])->setPourcentage(50.0);
        $em->find(Taxe::class, $c['taxe'])->setTauxIARD('20')->setTauxVIE('20');
        $em->flush();
        $em->clear();
    }

    /** @return array<string, array> les écritures du journal, par pièce — lues sur un noyau neuf (le service les met en cache) */
    private function journal(int $entreprise): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $parPiece = [];
        foreach (static::getContainer()->get(CourtierEcritureComptableService::class)->ecritures($this->em()->find(Entreprise::class, $entreprise)) as $e) {
            $parPiece[$e['piece']] = $e;
        }

        return $parPiece;
    }

    /** @return array{debit: array<string, float>, credit: array<string, float>} */
    private static function comptes(array $ecriture): array
    {
        $r = ['debit' => [], 'credit' => []];
        foreach ($ecriture['lignes'] as $l) {
            $r['debit'][(string) $l['compte']] = ($r['debit'][(string) $l['compte']] ?? 0.0) + $l['debit'];
            $r['credit'][(string) $l['compte']] = ($r['credit'][(string) $l['compte']] ?? 0.0) + $l['credit'];
        }

        return $r;
    }

    public function testUneNoteValideeGardeSonMontantUnBrouillonSuit(): void
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $validee = $this->valider($this->brouillon($c, 'Validée'))->getId();
        $brouillon = $this->brouillon($c, 'Brouillon');

        self::assertSame(['ht' => 1000.0, 'ttc' => 1160.0], $this->montants($validee));
        $note = $this->note($validee);
        self::assertTrue($note->estFigee());
        self::assertSame('TVA', $note->getLibelleTaxeFige());
        self::assertEqualsWithDelta(16.0, $note->getTauxTaxeFige(), 0.0001);

        $this->toutModifier($c);

        self::assertSame(['ht' => 1000.0, 'ttc' => 1160.0], $this->montants($validee), 'La note émise ne bouge pas.');
        // Le brouillon suit : 20 000 × 20 % = 4 000, à 50 % = 2 000 HT, taxe 20 % = 2 400 TTC.
        self::assertSame(['ht' => 2000.0, 'ttc' => 2400.0], $this->montants($brouillon), 'Le brouillon, lui, suit.');
    }

    public function testLEcritureDUneNoteValideeNeChangePas(): void
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $note = $this->valider($this->brouillon($c, 'Émise'));
        $reference = $note->getReference();
        $emiseLe = $note->getEmiseLe();

        $avant = $this->journal($c['entreprise'])[$reference];
        $this->toutModifier($c);
        $apres = $this->journal($c['entreprise'])[$reference];

        self::assertSame(self::comptes($avant), self::comptes($apres), 'Aucune écriture d’une note validée ne change après coup.');
        self::assertEqualsWithDelta(1160.0, self::comptes($apres)['debit'][PlanComptable::CLIENTS], 0.01);
        self::assertSame($emiseLe->format('Y-m-d H:i:s'), $apres['date']->format('Y-m-d H:i:s'), 'Datée de son émission.');
    }

    /** Un AVOIR ventile HT et taxe comme la note qu'il annule (son « HT » valait le TTC). */
    public function testUnAvoirVentileHtEtTaxe(): void
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $avoir = $this->valider($this->brouillon($c, 'Avoir', Note::TYPE_NOTE_DE_CREDIT));

        self::assertSame(['ht' => 1000.0, 'ttc' => 1160.0], $this->montants((int) $avoir->getId()));
        $comptes = self::comptes($this->journal($c['entreprise'])[$avoir->getReference()]);
        self::assertEqualsWithDelta(1000.0, $comptes['debit'][PlanComptable::SERVICES_VENDUS] ?? 0.0, 0.01);
        self::assertEqualsWithDelta(160.0, $comptes['debit'][PlanComptable::TVA_FACTUREE] ?? 0.0, 0.01, 'La taxe rendue passe au 443.');
        self::assertEqualsWithDelta(1160.0, $comptes['credit'][PlanComptable::CLIENTS] ?? 0.0, 0.01);
    }

    /** L'avoir à l'autorité fiscale respecte l'exonération : rien à reverser sur une affaire exonérée. */
    public function testLAvoirALAutoriteFiscaleRespecteLExoneration(): void
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL, clientExonere: true);
        $id = $this->brouillon($c, 'Taxe à reverser', Note::TYPE_NOTE_DE_CREDIT, adresse: Note::TO_AUTORITE_FISCALE);

        self::assertSame(0.0, $this->montants($id)['ttc'], 'Un client exonéré ne porte aucune taxe : il n’y a rien à reverser.');
    }

    /**
     * L'EXISTANT : la commande fige, à leur montant ACTUEL, les notes validées avant la règle
     * — à blanc d'abord. Sans utilisateur connecté, la taxe se calcule quand même.
     */
    public function testLaCommandeFigeLExistantSansUtilisateurConnecte(): void
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $id = $this->brouillon($c, 'Validée avant la règle');
        // Une note validée AVANT la règle : déjà émise, jamais figée.
        $this->em()->getConnection()->executeStatement('UPDATE note SET validated = 1, emise_le = NOW() WHERE id = ?', [$id]);

        $application = new Application(self::$kernel);
        $commande = new CommandTester($application->find('app:notes:figer'));

        $commande->execute([]);
        self::assertStringContainsString('1 160,00', $commande->getDisplay(), 'Le TTC, taxe comprise, même sans utilisateur connecté.');
        self::assertFalse($this->note($id)->estFigee(), 'À blanc : rien n’est écrit.');

        $commande->execute(['--confirmer' => true]);
        $note = $this->note($id);
        self::assertTrue($note->estFigee());
        self::assertEqualsWithDelta(1160.0, $note->getMontantTtcFige(), 0.001);

        $commande->execute([]);
        self::assertStringContainsString('Aucune note à figer', $commande->getDisplay(), 'Rejouée, elle ne trouve plus rien.');
    }

    /** UNE NOTE DE REPRISE : hors série (« REPRISE-… »), non validée, mais figée à l'import. */
    public function testUneNoteDeRepriseEstFigeeHorsSerie(): void
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $em = $this->em();
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $scope = new AiScope($em->find(Entreprise::class, $c['entreprise']), $em->find(Invite::class, $c['invite']));
        static::getContainer()->get('security.token_storage')->setToken(
            new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($em->find(Utilisateur::class, $c['owner']), 'main', ['ROLE_USER']),
        );

        $operation = new MutationOperation(
            op: MutationOperation::OP_CREATE,
            entityShortName: 'Note',
            fields: ['nom' => 'Reprise', 'type' => Note::TYPE_NOTE_DE_DEBIT, 'addressedTo' => Note::TO_ASSUREUR,
                'assureur' => $c['assureur'], 'origine' => Note::ORIGINE_REPRISE],
            collections: ['articles' => [new MutationOperation(
                op: MutationOperation::OP_CREATE,
                entityShortName: 'Article',
                fields: ['revenuFacture' => $c['revenu'], 'tranche' => $c['tranche'], 'quantite' => 1],
            )]],
        );
        // L'ORIGINE est un paramètre que seul le moteur de reprise passe.
        $em->wrapInTransaction(fn () => $service->executer($operation, $scope, $em->find(Utilisateur::class, $c['owner']), null, false, true, OrigineEcriture::Reprise));

        $note = $this->em()->getRepository(Note::class)->findOneBy(['nom' => 'Reprise']);
        self::assertMatchesRegularExpression('/^REPRISE-[0-9A-F]{8}$/', $note->getReference());
        self::assertFalse($note->isValidated(), 'Pas une facture émise par Joseara : aucune créance au journal.');
        self::assertTrue($note->estDeReprise());
        self::assertTrue($note->estFigee());
        self::assertEqualsWithDelta(1160.0, $note->getMontantTtcFige(), 0.001);

        // Et elle ne se valide pas : un numéro de la série et une créance d'aujourd'hui pour
        // une commission d'hier feraient glisser le chiffre d'affaires d'une période passée.
        try {
            static::getContainer()->get(EmissionDeNote::class)->valider($note);
            self::fail('Une note de reprise ne se valide pas.');
        } catch (NoteNonValidable $refus) {
            self::assertStringContainsString('ne se valide pas', $refus->getMessage());
        }
    }

    /** Un CHAMP « origine » ne fait pas une note de reprise : Ket n'obtient pas d'une phrase une note hors série. */
    public function testUnChampOrigineNeFaitPasUneReprise(): void
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $em = $this->em();
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $scope = new AiScope($em->find(Entreprise::class, $c['entreprise']), $em->find(Invite::class, $c['invite']));
        static::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($em->find(Utilisateur::class, $c['owner']), 'main', ['ROLE_USER']),
        );
        $operation = new MutationOperation(
            op: MutationOperation::OP_CREATE,
            entityShortName: 'Note',
            fields: ['nom' => 'Pas une reprise', 'type' => Note::TYPE_NOTE_DE_DEBIT, 'addressedTo' => Note::TO_ASSUREUR,
                'assureur' => $c['assureur'], 'origine' => Note::ORIGINE_REPRISE],
        );
        $em->wrapInTransaction(fn () => $service->executer($operation, $scope, $em->find(Utilisateur::class, $c['owner'])));

        $note = $this->em()->getRepository(Note::class)->findOneBy(['nom' => 'Pas une reprise']);
        self::assertFalse($note->estDeReprise());
        self::assertFalse($note->estFigee());
        self::assertStringStartsWith('BROUILLON-', $note->getReference());
    }

    /**
     * UN AVOIR SE VENTILE COMME LA NOTE QU'IL ANNULE, pas au taux du jour : le client devenu
     * exonéré depuis l'émission, l'avoir rend quand même la taxe que la note avait portée.
     */
    public function testUnAvoirGardeLaVentilationDeLaNoteQuIlAnnule(): void
    {
        $c = $this->semerUneAffaire(self::CABINET, self::EMAIL);
        $this->valider($this->brouillon($c, 'Débit'));
        $this->em()->find(Client::class, $c['client'])->setExonere(true);
        $this->em()->flush();

        // L'avoir d'une unité rend TOUT ce qui a été émis — 1 160, dont 1 000 HT et 160 de
        // taxe —, et non le TTC du jour (1 000, le client étant devenu exonéré).
        $avoir = $this->valider($this->brouillon($c, 'Avoir', Note::TYPE_NOTE_DE_CREDIT));
        $montants = $this->montants((int) $avoir->getId());
        self::assertEqualsWithDelta(1160.0, $montants['ttc'], 0.01);
        self::assertEqualsWithDelta(1000.0, $montants['ht'], 0.01, 'Au rapport HT/TTC figé de la note annulée.');
    }
}
