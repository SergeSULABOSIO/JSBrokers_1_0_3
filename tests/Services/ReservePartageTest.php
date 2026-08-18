<?php

namespace App\Tests\Services;

use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Service\Partage\Reserve;
use App\Services\Canvas\Indicator\AvenantIndicatorStrategy;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Canvas\Indicator\RevenuPourCourtierIndicatorStrategy;
use App\Services\Canvas\Indicator\TrancheIndicatorStrategy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA RÉSERVE DU CABINET N'A QU'UNE SEULE DÉFINITION.
 *
 * « Réserve » = ce qui reste au courtier après toutes les taxes et toutes les
 * rétrocommissions. Quatre écrans l'annoncent — la fiche d'une tranche, celle d'un
 * avenant, celle d'un revenu, et les agrégats de toutes les rubriques — et chacun la
 * calculait chez lui. Quatre écritures d'une même règle, donc quatre occasions de
 * diverger sans que rien ne le signale.
 *
 * Ce test tient les deux bouts :
 *  1. la FORMULE elle-même (App\Service\Partage\Reserve), y compris son cas déficitaire ;
 *  2. l'ACCORD des quatre consommateurs sur une même affaire — une police, une tranche
 *     à 100 %, un revenu partageable, un partenaire à 30 %. Les quatre doivent annoncer
 *     le MÊME montant, au centime.
 *
 * Le troisième terme (rétrocommission des agents INTERNES) est nul ici : ce test est
 * écrit avant son branchement, précisément pour prouver que l'introduction de la source
 * unique n'a rien déplacé.
 */
class ReservePartageTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-reserve-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Reserve SARL';

    private const PRIME = 1000.0;
    private const COMMISSION_HT = 200.0;
    private const TAUX_TAXE_COURTIER = 2.0;   // ARCA, en POINTS
    private const TAUX_TAXE_ASSUREUR = 16.0;  // TVA, en POINTS
    private const PART_PARTENAIRE = 30.0;     // en POINTS

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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement(
            'DELETE pp FROM piste_partenaire pp JOIN piste p ON pp.piste_id = p.id
             JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach ([
            'condition_partage', 'avenant', 'tranche', 'revenu_pour_courtier', 'type_revenu',
            'chargement_pour_prime', 'cotation', 'piste', 'client', 'partenaire', 'risque',
            'taxe', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    // ===================== 1. La formule =====================

    public function testLaReserveRetireLesTaxesPuisLesDeuxRetrocommissions(): void
    {
        // Commission pure 100, rétro partenaire 30, rétro agent 15 → il reste 55.
        self::assertSame(55.0, Reserve::calculer(100.0, 30.0, 15.0));
    }

    public function testSansRetroAgentLaReserveEstCelleDAvant(): void
    {
        // Le troisième terme est optionnel : le comportement historique est préservé
        // à l'identique pour tout appelant qui ne le passe pas.
        self::assertSame(Reserve::calculer(100.0, 30.0), Reserve::calculer(100.0, 30.0, 0.0));
    }

    public function testLaReserveNEstPasEcreteeAZeroQuandLesTauxDepassentCent(): void
    {
        // Deux agents à 60 % chacun sur la même affaire : le cabinet reverse plus qu'il
        // ne garde. On rend le négatif TEL QUEL — un plancher à zéro masquerait
        // précisément le paramétrage à corriger.
        self::assertSame(-20.0, Reserve::calculer(100.0, 0.0, 120.0));
        self::assertTrue(Reserve::estDeficitaire(100.0, 0.0, 120.0));
    }

    public function testUneAffaireSansCommissionNEstPasDeficitaire(): void
    {
        // Réserve nulle, mais rien n'a été sur-rétrocédé : ne rien avoir à partager
        // n'est pas un déficit, et ne doit déclencher aucun avertissement.
        self::assertFalse(Reserve::estDeficitaire(0.0, 0.0, 0.0));
    }

    // ===================== 2. L'accord des quatre écrans =====================

    public function testLesQuatreConsommateursAnnoncentLaMemeReserve(): void
    {
        $ids = $this->semer();
        $em = $this->em();

        $cotation = $em->getRepository(Cotation::class)->find($ids['cotationId']);
        $avenant  = $em->getRepository(Avenant::class)->find($ids['avenantId']);
        $tranche  = $em->getRepository(Tranche::class)->find($ids['trancheId']);
        $revenu   = $em->getRepository(RevenuPourCourtier::class)->find($ids['revenuId']);
        $entreprise = $em->getRepository(Entreprise::class)->find($ids['entrepriseId']);

        $helper = static::getContainer()->get(IndicatorCalculationHelper::class);

        // La valeur attendue est RECONSTITUÉE depuis les deux primitives du helper, par
        // un chemin de code distinct de celui de `reserve` : le test échoue donc aussi le
        // jour où les quatre consommateurs se tromperaient de la même façon.
        //
        // NOTE — les taxes valent zéro ici. ServiceTaxes scope son barème sur
        // l'entreprise CONNECTÉE, et un KernelTestCase n'a pas d'utilisateur connecté :
        // `getTaxes()` rend un tableau vide. La commission « pure » égale donc la
        // commission HT dans ce test. Ce n'est pas ce qu'il prouve : son objet est
        // l'ACCORD des quatre écrans sur une même formule, pas le barème fiscal — celui-ci
        // est couvert par ServiceTaxesScopingTest et CotationTauxTaxeTest.
        $commissionPure = $helper->getCotationMontantCommissionPure($cotation, -1, false);
        $retroPartenaire = $helper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1);
        $attendue = Reserve::calculer($commissionPure, $retroPartenaire);

        // Le taux du partenaire est bien celui qui s'applique : 30 % de moins que la pure.
        self::assertSame(
            round($commissionPure * (1 - self::PART_PARTENAIRE / 100), 2),
            $attendue,
            'La réserve retire exactement la part du partenaire.',
        );

        $globale = round($helper->getIndicateursGlobaux($entreprise, true, ['cotationCible' => $cotation])['reserve'], 2);
        $surAvenant = round(static::getContainer()->get(AvenantIndicatorStrategy::class)->calculate($avenant)['reserve'], 2);
        $surTranche = round(static::getContainer()->get(TrancheIndicatorStrategy::class)->calculate($tranche)['reserve'], 2);
        $surRevenu = round(static::getContainer()->get(RevenuPourCourtierIndicatorStrategy::class)->calculate($revenu)['reserve'], 2);

        self::assertSame($attendue, $globale, 'Agrégat global');
        self::assertSame($attendue, $surAvenant, 'Fiche Avenant');
        // La tranche vaut 100 % de sa cotation : sa réserve est donc celle de l'affaire.
        self::assertSame($attendue, $surTranche, 'Fiche Tranche');
        self::assertSame($attendue, $surRevenu, 'Fiche Revenu');
    }

    /**
     * Une affaire complète et souscrite : prime 1 000, commission HT 200, taxe courtier
     * 2 %, taxe assureur 16 %, un partenaire à 30 % sur un revenu partageable, une
     * tranche unique portant 100 % de l'affaire.
     *
     * @return array{entrepriseId:int, cotationId:int, avenantId:int, trancheId:int, revenuId:int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Reserve Owner')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($invite);

        foreach ([
            [Taxe::REDEVABLE_COURTIER, self::TAUX_TAXE_COURTIER, 'ARCA'],
            [Taxe::REDEVABLE_ASSUREUR, self::TAUX_TAXE_ASSUREUR, 'TVA'],
        ] as [$redevable, $taux, $code]) {
            $taxe = (new Taxe())->setCode($code)->setDescription('Taxe ' . $code)
                ->setRedevable($redevable)->setTauxIARD($taux)->setTauxVIE($taux);
            $taxe->setEntreprise($entreprise);
            $em->persist($taxe);
        }

        $risque = (new Risque())->setCode('RR')->setNomComplet('Risque Réserve')->setDescription('Risque de la réserve')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $partenaire = (new Partenaire())->setNom('Partenaire Réserve')->setPart(self::PART_PARTENAIRE);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $client = (new Client())->setNom('Client Réserve')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste Réserve')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque réserve')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $piste->addPartenaire($partenaire);
        $em->persist($piste);

        $cotation = (new Cotation())->setNom('Cotation Réserve')->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())->setNom('Prime')->setMontantFlatExceptionel(self::PRIME);
        $chargement->setEntreprise($entreprise);
        $cotation->addChargement($chargement);
        $em->persist($chargement);

        $typeRevenu = (new TypeRevenu())->setNom('Commission')
            ->setMontantflat(self::COMMISSION_HT)
            ->setShared(true)->setMultipayments(true)
            ->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())->setNom('Revenu')->setTypeRevenu($typeRevenu)->setCotation($cotation);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        // Tranche unique à 100 % : sa quote-part vaut l'affaire entière, ce qui rend sa
        // réserve directement comparable à celle de l'avenant et de l'agrégat.
        $tranche = (new Tranche())->setNom('Tranche unique')->setPourcentage(100.0)
            ->setPayableAt(new \DateTimeImmutable('-30 days'))
            ->setEcheanceAt(new \DateTimeImmutable('+30 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);

        // Sans avenant, la cotation reste une proposition : elle n'est jamais agrégée.
        $avenant = (new Avenant())->setReferencePolice('POL-RESERVE')->setNumero('0')
            ->setDescription('Police réserve')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))
            ->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($entreprise)->setInvite($invite);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $em->flush();
        $ids = [
            'entrepriseId' => (int) $entreprise->getId(),
            'cotationId'   => (int) $cotation->getId(),
            'avenantId'    => (int) $avenant->getId(),
            'trancheId'    => (int) $tranche->getId(),
            'revenuId'     => (int) $revenu->getId(),
        ];
        $em->clear();

        return $ids;
    }
}
