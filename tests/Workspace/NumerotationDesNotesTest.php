<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Scope\AiScope;
use App\Entity\Article;
use App\Entity\Assureur;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Service\Note\EmissionDeNote;
use App\Service\Note\NumerotationDesNotes;
use App\Service\Workspace\MutationException;
use App\Service\Workspace\WorkspaceMutationService;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LE NUMÉRO D'UNE NOTE NAÎT À SA VALIDATION — continu, chronologique, sans trou ni doublon
 * (pratique comptable OHADA, décision de Serge du 2026-10-15).
 *
 * Un brouillon porte une référence provisoire (`BROUILLON-…`) et ne consomme rien ; la
 * validation pose `ND-AAAA-NNNNN` / `NC-AAAA-NNNNN` et la date d'émission, dans une
 * transaction : une validation qui échoue rend son numéro à la série. Avant : le numéro
 * était pris à la CRÉATION, la référence restait saisissable, et un échec d'écriture
 * pouvait laisser un trou.
 */
class NumerotationDesNotesTest extends WebTestCase
{
    use AffaireFacturableTrait;

    private const EMAIL_A = 'phpunit-emission-a@test.local';
    private const EMAIL_B = 'phpunit-emission-b@test.local';
    private const CABINET_A = 'PHPUnit Emission A SARL';
    private const CABINET_B = 'PHPUnit Emission B SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    /** Après une transaction annulée, Doctrine ferme le gestionnaire : on en reprend un neuf. */
    private function emNeuf(): EntityManagerInterface
    {
        static::getContainer()->get('doctrine')->resetManager();

        return $this->em();
    }

    private function cleanUp(): void
    {
        $this->effacerLesCabinets([self::CABINET_A, self::CABINET_B], [self::EMAIL_A, self::EMAIL_B]);
    }

    private static function annee(): int
    {
        return (int) (new \DateTimeImmutable())->format('Y');
    }

    private static function numero(string $prefixe, int $sequence): string
    {
        return sprintf('%s-%d-%05d', $prefixe, self::annee(), $sequence);
    }

    private function connecter(array $c): void
    {
        $this->client->loginUser($this->em()->find(Utilisateur::class, $c['owner']));
    }

    /** Valide par le geste d'écran ; rend le statut et le message. */
    private function validerALEcran(int $id): array
    {
        $this->client->request('POST', '/admin/note/api/' . $id . '/valider');
        $reponse = $this->client->getResponse();

        return ['statut' => $reponse->getStatusCode(), 'corps' => json_decode((string) $reponse->getContent(), true) ?? []];
    }

    private function sequenceDeLaSerie(int $entreprise, int $type = Note::TYPE_NOTE_DE_DEBIT): ?int
    {
        $valeur = $this->em()->getConnection()->fetchOne(
            'SELECT sequence FROM compteur_de_notes WHERE entreprise_id = ? AND annee = ? AND type = ?',
            [$entreprise, self::annee(), $type],
        );

        return $valeur === false ? null : (int) $valeur;
    }

    public function testUnBrouillonPorteUneReferenceProvisoireEtNeConsommeAucunNumero(): void
    {
        $c = $this->semerUneAffaire(self::CABINET_A, self::EMAIL_A);
        $this->connecter($c);

        $this->client->request('POST', '/admin/note/api/submit', [
            'idEntreprise' => $c['entreprise'], 'idInvite' => $c['invite'], 'nom' => 'Brouillon du dialogue',
            'type' => (string) Note::TYPE_NOTE_DE_DEBIT, 'addressedTo' => (string) Note::TO_ASSUREUR, 'assureur' => (string) $c['assureur'],
            // La référence ne se saisit plus : un champ envoyé quand même est ignoré.
            'reference' => 'ND-2026-99999',
        ]);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $note = $this->em()->getRepository(Note::class)->findOneBy(['nom' => 'Brouillon du dialogue']);
        self::assertMatchesRegularExpression('/^BROUILLON-[0-9A-F]{8}$/', $note->getReference());
        self::assertFalse($note->isValidated());
        self::assertNull($note->getEmiseLe());
        self::assertNull($this->sequenceDeLaSerie($c['entreprise']), 'Un brouillon ne touche pas la série.');
    }

    public function testLaValidationPoseLeNumeroSuivantEtLaDateDEmission(): void
    {
        $c = $this->semerUneAffaire(self::CABINET_A, self::EMAIL_A);
        $this->connecter($c);
        $premiere = $this->brouillon($c, 'Première');
        $seconde = $this->brouillon($c, 'Seconde');

        // L'ordre de VALIDATION fait le numéro, pas l'ordre de création.
        $reponse = $this->validerALEcran($seconde);
        self::assertSame(200, $reponse['statut'], json_encode($reponse['corps'], JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString(self::numero('ND', 1), $reponse['corps']['message']);
        $this->validerALEcran($premiere);

        $a = $this->note($seconde);
        $b = $this->note($premiere);
        self::assertSame(self::numero('ND', 1), $a->getReference());
        self::assertSame(self::numero('ND', 2), $b->getReference());
        self::assertTrue($b->isValidated());
        self::assertNotNull($a->getEmiseLe());
        self::assertGreaterThanOrEqual($a->getEmiseLe(), $b->getEmiseLe(), 'Jamais émise avant la précédente de sa série.');
    }

    /** Une série par type et par cabinet ; un avoir a la sienne. */
    public function testUneSerieParTypeEtParCabinet(): void
    {
        $a = $this->semerUneAffaire(self::CABINET_A, self::EMAIL_A);
        $b = $this->semerUneAffaire(self::CABINET_B, self::EMAIL_B);
        $emission = static::getContainer()->get(EmissionDeNote::class);

        $emission->valider($this->note($this->brouillon($a, 'Débit A')));
        $emission->valider($this->note($this->brouillon($a, 'Crédit A', Note::TYPE_NOTE_DE_CREDIT)));
        $emission->valider($this->note($this->brouillon($b, 'Débit B')));

        $refs = $this->em()->getConnection()->fetchAllKeyValue('SELECT nom, reference FROM note WHERE nom IN (?, ?, ?)', ['Débit A', 'Crédit A', 'Débit B']);
        self::assertSame(self::numero('ND', 1), $refs['Débit A']);
        self::assertSame(self::numero('NC', 1), $refs['Crédit A']);
        self::assertSame(self::numero('ND', 1), $refs['Débit B']);
    }

    /**
     * UNE VALIDATION QUI ÉCHOUE NE CONSOMME AUCUN NUMÉRO. L'échec est injecté APRÈS la prise
     * du numéro (un écouteur `onFlush` posé derrière celui de la numérotation) : la
     * transaction annulée rend l'incrément à la série, et la validation suivante reprend
     * le même numéro.
     */
    public function testUneValidationQuiEchoueNeConsommeAucunNumero(): void
    {
        $c = $this->semerUneAffaire(self::CABINET_A, self::EMAIL_A);
        $id = $this->brouillon($c, 'Échouera puis passera');
        $em = $this->em();
        $saboteur = new class {
            public bool $actif = true;

            public function onFlush(OnFlushEventArgs $args): void
            {
                if ($this->actif) {
                    throw new \RuntimeException('Panne injectée après la prise du numéro.');
                }
            }
        };
        $em->getEventManager()->addEventListener([Events::onFlush], $saboteur);

        try {
            static::getContainer()->get(EmissionDeNote::class)->valider($this->note($id));
            self::fail('La panne injectée doit faire échouer la validation.');
        } catch (\RuntimeException $panne) {
            self::assertSame('Panne injectée après la prise du numéro.', $panne->getMessage());
        } finally {
            $saboteur->actif = false;
        }

        self::assertSame(0, $this->sequenceDeLaSerie($c['entreprise']) ?? 0, 'La série est intacte.');
        $em = $this->emNeuf();
        self::assertTrue($em->find(Note::class, $id)->estBrouillon());

        static::getContainer()->get(EmissionDeNote::class)->valider($em->find(Note::class, $id));
        self::assertSame(self::numero('ND', 1), $this->note($id)->getReference(), 'Pas de trou : le numéro 1 est repris.');
    }

    /**
     * DEUX VALIDATIONS SIMULTANÉES : ni doublon, ni trou. Une seconde connexion prend un
     * numéro et garde sa transaction ouverte : la validation d'ici ATTEND (jusqu'au délai
     * d'une seconde) au lieu de lire la même séquence. L'autre transaction ANNULÉE, son
     * numéro revient à la série : la validation d'ici prend le 1, sans trou.
     */
    public function testDeuxValidationsSimultaneesNiDoublonNiTrou(): void
    {
        $c = $this->semerUneAffaire(self::CABINET_A, self::EMAIL_A);
        $id = $this->brouillon($c, 'Concurrente');
        $connexion = $this->em()->getConnection();
        $autre = DriverManager::getConnection($connexion->getParams());

        $autre->beginTransaction();
        self::assertSame(self::numero('ND', 1), (new NumerotationDesNotes($autre))->prochaine($c['entreprise'], Note::TYPE_NOTE_DE_DEBIT, self::annee()));

        $connexion->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            static::getContainer()->get(EmissionDeNote::class)->valider($this->note($id));
            self::fail('Tant que l’autre validation n’est pas terminée, la série est verrouillée.');
        } catch (\Doctrine\DBAL\Exception\LockWaitTimeoutException) {
        } finally {
            $autre->rollBack();
            $autre->close();
            $connexion->executeStatement('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $em = $this->emNeuf();
        static::getContainer()->get(EmissionDeNote::class)->valider($em->find(Note::class, $id));
        self::assertSame(self::numero('ND', 1), $this->note($id)->getReference(), 'Le numéro de la validation annulée revient à la série.');
    }

    public function testLaSuppressionDUnBrouillonNeLaissePasDeTrou(): void
    {
        $c = $this->semerUneAffaire(self::CABINET_A, self::EMAIL_A);
        $this->connecter($c);
        $abandonne = $this->brouillon($c, 'Abandonné');
        $garde = $this->brouillon($c, 'Gardé');

        $this->client->request('DELETE', '/admin/note/api/delete/' . $abandonne);
        self::assertResponseIsSuccessful();
        $this->validerALEcran($garde);

        self::assertSame(self::numero('ND', 1), $this->note($garde)->getReference());
    }

    public function testUneNoteSansLigneOuDUnAutreCabinetNeSeValidePas(): void
    {
        $a = $this->semerUneAffaire(self::CABINET_A, self::EMAIL_A);
        $b = $this->semerUneAffaire(self::CABINET_B, self::EMAIL_B);
        $this->connecter($a);

        $vide = $this->validerALEcran($this->brouillon($a, 'Sans ligne', avecLigne: false));
        self::assertSame(409, $vide['statut']);
        self::assertSame('Une note sans ligne ne se valide pas : ajoutez-lui au moins un article.', $vide['corps']['message']);

        $etrangere = $this->validerALEcran($this->brouillon($b, 'Chez B'));
        self::assertSame(404, $etrangere['statut']);
        self::assertTrue($this->note($this->em()->getRepository(Note::class)->findOneBy(['nom' => 'Chez B'])->getId())->estBrouillon());
        self::assertNull($this->sequenceDeLaSerie($a['entreprise']));
    }

    /** Ket valide par le même service : en créant une note validée, ou en validant un brouillon. */
    public function testKetValideParLeMemeService(): void
    {
        $c = $this->semerUneAffaire(self::CABINET_A, self::EMAIL_A);
        // Les sélecteurs du formulaire sont filtrés par le cabinet de l'utilisateur connecté.
        $this->connecter($c);
        $em = $this->em();
        $service = static::getContainer()->get(WorkspaceMutationService::class);
        $scope = new AiScope($em->find(Entreprise::class, $c['entreprise']), $em->find(Invite::class, $c['invite']));
        $acteur = $em->find(Utilisateur::class, $c['owner']);

        // Création validée, avec sa ligne : la validation vient APRÈS les lignes.
        $creation = new MutationOperation(
            op: MutationOperation::OP_CREATE,
            entityShortName: 'Note',
            fields: ['nom' => 'Facturée par Ket', 'type' => Note::TYPE_NOTE_DE_DEBIT, 'addressedTo' => Note::TO_ASSUREUR,
                'assureur' => $c['assureur'], 'validated' => true],
            collections: ['articles' => [new MutationOperation(
                op: MutationOperation::OP_CREATE,
                entityShortName: 'Article',
                fields: ['revenuFacture' => $c['revenu'], 'tranche' => $c['tranche'], 'quantite' => 1],
            )]],
        );
        try {
            $em->wrapInTransaction(fn () => $service->executer($creation, $scope, $acteur));
        } catch (MutationException $e) {
            self::fail($e->getMessage() . ' ' . json_encode($e->erreursChamps, JSON_UNESCAPED_UNICODE));
        }
        $facturee = $this->em()->getRepository(Note::class)->findOneBy(['nom' => 'Facturée par Ket']);
        self::assertSame(self::numero('ND', 1), $facturee->getReference());
        self::assertTrue($facturee->isValidated());

        // Un brouillon existant, validé par une modification `validated: true`.
        $brouillon = $this->brouillon($c, 'Brouillon pour Ket');
        $em = $this->em();
        $scope = new AiScope($em->find(Entreprise::class, $c['entreprise']), $em->find(Invite::class, $c['invite']));
        $edition = new MutationOperation('edit', 'Note', $brouillon, ['validated' => true]);
        self::assertTrue($service->analyserOperation($edition, $scope)['ok']);
        $em->wrapInTransaction(fn () => $service->executer($edition, $scope, $em->find(Utilisateur::class, $c['owner'])));
        self::assertSame(self::numero('ND', 2), $this->note($brouillon)->getReference());

        // Un brouillon VIDE : refusé dès l'analyse, et à l'exécution.
        $vide = $this->brouillon($c, 'Vide pour Ket', avecLigne: false);
        $em = $this->em();
        $scope = new AiScope($em->find(Entreprise::class, $c['entreprise']), $em->find(Invite::class, $c['invite']));
        $refus = new MutationOperation('edit', 'Note', $vide, ['validated' => true]);
        $analyse = $service->analyserOperation($refus, $scope);
        self::assertFalse($analyse['ok']);
        self::assertStringContainsString('Une note sans ligne ne se valide pas', json_encode($analyse, JSON_UNESCAPED_UNICODE));
        try {
            $em->wrapInTransaction(fn () => $service->executer($refus, $scope, $em->find(Utilisateur::class, $c['owner'])));
            self::fail('L’exécution refuse aussi.');
        } catch (MutationException) {
        }
        self::assertSame(2, $this->sequenceDeLaSerie($c['entreprise']));
    }
}
