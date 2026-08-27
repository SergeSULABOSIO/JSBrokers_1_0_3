<?php

namespace App\Tests\Services;

use App\Entity\Article;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * LA REPRISE DE L'HISTORIQUE : ce qui a été payé reste payé.
 *
 * Le « payé » d'un partenaire se déduisait du prorata des règlements d'une note de crédit ;
 * il se lit désormais sur les reversements. Sans reprise, tout ce qui a DÉJÀ été réglé par
 * l'ancien circuit repasserait en « non payé » : le cabinet croirait devoir ce qu'il a versé,
 * et un intermédiaire pourrait réclamer deux fois.
 *
 * ── CE QUE CE TEST TIENT ────────────────────────────────────────────────────────────
 *
 *  1. LE MONTANT, AU CENTIME. La part reprise est celle RÉGLÉE — proportion des paiements
 *     de la note appliquée au montant de l'article — et non le montant facturé. Reprendre
 *     le facturé transformerait une dette partielle en dette éteinte.
 *  2. LA MAILLE. Chaque article porte sa tranche : l'échéance exacte est conservée, aucune
 *     répartition n'est inventée.
 *  3. L'IDEMPOTENCE. Une seconde exécution ne double rien — c'est ce qui permet de relancer
 *     la commande après correction d'un cas particulier.
 *  4. LE DRY-RUN N'ÉCRIT RIEN. Sur des montants, on regarde avant d'agir.
 */
class RepriseNotesVersReversementsTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-reprise-notes@test.local';
    private const ENT = 'PHPUnit Reprise Notes SARL';

    private const COMMISSION = 1000.0;
    private const PART_PARTENAIRE = 20.0;
    /** La note est réglée à MOITIÉ : c'est ce qui distingue le réglé du facturé. */
    private const PROPORTION_REGLEE = 0.5;

    protected function setUp(): void
    {
        static::bootKernel();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function helper(): IndicatorCalculationHelper
    {
        return static::getContainer()->get(IndicatorCalculationHelper::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $tables = [
            'paiement', 'article', 'note', 'reversement_retro_agent', 'tranche', 'avenant',
            'revenu_pour_courtier', 'type_revenu', 'chargement_pour_prime', 'cotation',
            'piste', 'client', 'assureur', 'partenaire', 'risque', 'invite',
        ];
        foreach ($tables as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENT],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * Une affaire de partenaire, DEUX échéances, et une note de crédit réglée à moitié
     * couvrant les deux : de quoi vérifier la maille ET le prorata.
     *
     * @return array{entrepriseId:int, partenaireId:int, cotationId:int, t1Id:int, t2Id:int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Reprise')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $gestionnaire = (new Invite())->setNom('Gestionnaire Reprise')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $assureur = (new Assureur())->setNom('Assureur Reprise')->setEmail('assureur-reprise@test.local')
            ->setNumimpot('IMP-R')->setIdnat('IDNAT-R')->setRccm('RCCM-R');
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        $partenaire = (new Partenaire())->setNom('SUNU Reprise')->setPart(self::PART_PARTENAIRE);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $risque = (new Risque())->setCode('REP')->setNomComplet('Risque reprise')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $client = (new Client())->setNom('Client reprise')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Affaire reprise')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'))->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($gestionnaire)->setPartenaire($partenaire);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation reprise')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($entreprise)->setAssureur($assureur);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())->setNom('Prime')->setMontantFlatExceptionel(5000.0);
        $chargement->setEntreprise($entreprise);
        $cotation->addChargement($chargement);
        $em->persist($chargement);

        $typeRevenu = (new TypeRevenu())->setNom('Commission')->setMontantflat(self::COMMISSION)
            ->setShared(true)->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())->setNom('Revenu')->setTypeRevenu($typeRevenu)->setCotation($cotation);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        $faireTranche = function (string $nom, float $part) use ($em, $entreprise, $cotation): Tranche {
            $t = (new Tranche())->setNom($nom)->setPourcentage($part)
                ->setPayableAt(new \DateTimeImmutable('-30 days'))
                ->setEcheanceAt(new \DateTimeImmutable('+30 days'));
            $t->setCotation($cotation)->setEntreprise($entreprise);
            $em->persist($t);

            return $t;
        };
        $t1 = $faireTranche('1re échéance', 60.0);
        $t2 = $faireTranche('2e échéance', 40.0);

        $avenant = (new Avenant())->setReferencePolice('POL-REP')->setNumero('0')->setDescription('Police')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        // LA NOTE DE CRÉDIT DE L'ANCIEN CIRCUIT : deux articles, un par échéance, et un
        // règlement PARTIEL — la moitié du payable.
        $note = (new Note())->setNom('Note rétro historique')->setType(Note::TYPE_NOTE_DE_CREDIT)
            ->setAddressedTo(Note::TO_PARTENAIRE)->setReference('NC-HIST-1')
            ->setValidated(true)->setSignature('')
            ->setPartenaire($partenaire);
        $note->setEntreprise($entreprise)->setInvite($gestionnaire);
        $em->persist($note);

        foreach ([$t1, $t2] as $tranche) {
            $article = (new Article())->setNote($note)->setRevenuFacture($revenu)->setTranche($tranche);
            $article->setEntreprise($entreprise);
            $em->persist($article);
        }

        $em->flush();

        // LE MONTANT EST ÉCRIT, PAS LU. Interroger le moteur ici donnerait zéro : la
        // collection `articles` de la note est INVERSE, et seul le côté propriétaire vient
        // d être posé — elle est encore vide en mémoire. On exprime donc l arithmétique :
        // 20 % de 1 000 de rétro, dont la moitié réglée.
        $reglement = (new Paiement())
            ->setMontant(round(self::COMMISSION * self::PART_PARTENAIRE / 100 * self::PROPORTION_REGLEE, 2))
            ->setReference('REG-HIST-1')->setPaidAt(new \DateTimeImmutable('-10 days'))->setNote($note);
        $reglement->setEntreprise($entreprise);
        $em->persist($reglement);
        $em->flush();

        $ids = [
            'entrepriseId' => (int) $entreprise->getId(),
            'partenaireId' => (int) $partenaire->getId(),
            'cotationId'   => (int) $cotation->getId(),
            't1Id'         => (int) $t1->getId(),
            't2Id'         => (int) $t2->getId(),
            'noteId'       => (int) $note->getId(),
        ];
        $em->clear();

        return $ids;
    }

    private function reprendre(array $ids, bool $simulation = false): string
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('app:reversements:reprise-notes'));
        $options = ['--entreprise' => (string) $ids['entrepriseId']];
        if ($simulation) {
            $options['--dry-run'] = true;
        }
        $tester->execute($options);
        $this->em()->clear();

        return $tester->getDisplay();
    }

    /** @return ReversementRetroAgent[] */
    private function reversements(array $ids): array
    {
        return $this->em()->getRepository(ReversementRetroAgent::class)->findBy(
            ['entreprise' => $ids['entrepriseId']],
            ['id' => 'ASC'],
        );
    }

    private function payeeLuParLeMoteur(array $ids): float
    {
        return $this->helper()->getCotationMontantRetrocommissionsPayableParCourtierPayee(
            $this->em()->getRepository(Cotation::class)->find($ids['cotationId']),
            $this->em()->getRepository(Partenaire::class)->find($ids['partenaireId']),
        );
    }

    /** Ce que l'ANCIEN circuit disait avoir payé : le prorata des règlements de la note. */
    private function payeeSelonLaNote(array $ids): float
    {
        $note = $this->em()->getRepository(Note::class)->find($ids['noteId']);
        $payable = $this->helper()->getNoteMontantPayable($note);
        $proportion = $payable > 0 ? min(1.0, $this->helper()->getNoteMontantPaye($note) / $payable) : 0.0;

        $montant = 0.0;
        foreach ($note->getArticles() as $article) {
            $montant += $proportion * $this->helper()->getArticleMontant($article);
        }

        return round($montant, 2);
    }

    // ===================== Avant la reprise =====================

    /**
     * SANS REPRISE, L'HISTORIQUE A DISPARU — c'est précisément le risque.
     *
     * La note est réglée, mais le moteur ne lit plus les notes : le payé vaut zéro.
     */
    public function testSansRepriseLePayeEstTombeAZero(): void
    {
        $ids = $this->semer();

        self::assertGreaterThan(0.0, $this->payeeSelonLaNote($ids), 'La note doit bien être réglée en partie.');
        self::assertEqualsWithDelta(0.0, $this->payeeLuParLeMoteur($ids), 0.01);
    }

    // ===================== La reprise =====================

    /**
     * LE MONTANT REPRIS EST CELUI RÉGLÉ, au centime — pas le facturé.
     *
     * Reprendre le facturé transformerait une dette à moitié éteinte en dette soldée.
     */
    public function testLaRepriseRestitueLePayeAuCentime(): void
    {
        $ids = $this->semer();
        $attendu = $this->payeeSelonLaNote($ids);

        $this->reprendre($ids);

        self::assertEqualsWithDelta($attendu, $this->payeeLuParLeMoteur($ids), 0.01);
    }

    /** LA MAILLE EST CONSERVÉE : une ligne par échéance, chacune sur la sienne. */
    public function testChaqueEcheanceRetrouveSaPart(): void
    {
        $ids = $this->semer();
        $this->reprendre($ids);

        $reversements = $this->reversements($ids);
        self::assertCount(2, $reversements, 'Une ligne par échéance couverte par la note.');

        $tranches = array_map(static fn (ReversementRetroAgent $r) => $r->getTranche()?->getId(), $reversements);
        self::assertContains($ids['t1Id'], $tranches);
        self::assertContains($ids['t2Id'], $tranches);
    }

    /**
     * LE LOT EST CONSERVÉ : une note réglant deux échéances redevient UN virement de deux
     * lignes, ce que le mécanisme de lot sait déjà lire — pièce justificative comprise.
     */
    public function testLesLignesDUneMemeNotePartagentLeurLot(): void
    {
        $ids = $this->semer();
        $this->reprendre($ids);

        $lots = array_unique(array_map(
            static fn (ReversementRetroAgent $r) => $r->getLotReference(),
            $this->reversements($ids),
        ));

        self::assertCount(1, $lots, 'Les deux lignes doivent appartenir au même virement.');
        self::assertSame('NC-HIST-1', reset($lots), 'Le lot porte la référence de la note reprise.');
    }

    /** La date reprise est celle du RÈGLEMENT, non celle du jour de la reprise. */
    public function testLaDateEstCelleDuReglement(): void
    {
        $ids = $this->semer();
        $this->reprendre($ids);

        $reversements = $this->reversements($ids);
        self::assertSame(
            (new \DateTimeImmutable('-10 days'))->format('Y-m-d'),
            $reversements[0]->getPaidAt()?->format('Y-m-d'),
        );
    }

    /** Le bénéficiaire repris est le PARTENAIRE, jamais un agent. */
    public function testLeBeneficiaireRepriseEstLePartenaire(): void
    {
        $ids = $this->semer();
        $this->reprendre($ids);

        foreach ($this->reversements($ids) as $reversement) {
            self::assertSame($ids['partenaireId'], $reversement->getPartenaire()?->getId());
            self::assertNull($reversement->getAgent());
            self::assertTrue($reversement->estValide(), 'Une ligne reprise doit respecter le XOR.');
        }
    }

    // ===================== Idempotence et simulation =====================

    /** UNE SECONDE EXÉCUTION NE DOUBLE RIEN : la commande est relançable. */
    public function testUneSecondeExecutionNeDoublePas(): void
    {
        $ids = $this->semer();
        $this->reprendre($ids);
        $apresUne = $this->payeeLuParLeMoteur($ids);

        $sortie = $this->reprendre($ids);

        self::assertCount(2, $this->reversements($ids), 'Aucune ligne supplémentaire.');
        self::assertEqualsWithDelta($apresUne, $this->payeeLuParLeMoteur($ids), 0.01);
        self::assertStringContainsString('déjà reprise', $sortie);
    }

    /** LE DRY-RUN N'ÉCRIT RIEN, mais compte et nomme ce qu'il ferait. */
    public function testLeDryRunNEcritRien(): void
    {
        $ids = $this->semer();

        $sortie = $this->reprendre($ids, simulation: true);

        self::assertSame([], $this->reversements($ids), 'La simulation ne doit rien persister.');
        self::assertStringContainsString('SIMULATION', $sortie);
        self::assertStringContainsString('NC-HIST-1', $sortie);
    }
}
