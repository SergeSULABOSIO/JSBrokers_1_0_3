<?php

namespace App\Tests\Services;

use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Service\Partage\Reserve;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA RÉTROCOMMISSION D'UN AGENT INTERNE.
 *
 * Le cabinet récompense l'effort commercial de ses propres agents : une ConditionPartage
 * à leur nom, rattachée à l'affaire, leur promet un pourcentage de ce qui RESTE au
 * cabinet — après les taxes, et après les partenaires externes.
 *
 * Ce test fixe les quatre règles dont dépend la justesse des montants, et dont aucune
 * n'est évidente :
 *
 *  1. L'ASSIETTE. C'est la réserve d'avant les agents (commission pure − rétros
 *     externes), pas la commission brute : on ne rétrocède que sur ce qu'on a gardé.
 *  2. L'INDÉPENDANCE À L'ORDRE. Deux agents sur une affaire se partagent la MÊME
 *     assiette. Les traiter en cascade rendrait les montants dépendants de l'ordre de
 *     parcours de la collection, donc instables d'une requête à l'autre.
 *  3. UN AGENT, UNE CONDITION. Deux conditions du même agent ne se cumulent pas : la
 *     première applicable l'emporte, comme dans la cascade des partenaires.
 *  4. L'EXIGIBILITÉ. Le dû naît à la souscription, mais n'est réclamable qu'une fois la
 *     commission encaissée par le cabinet — payer avant, c'est avancer sa trésorerie.
 */
class RetroCommissionAgentTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-retroagent-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit RetroAgent SARL';

    private const COMMISSION = 1000.0;
    private const PART_PARTENAIRE = 20.0; // POINTS

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
        $conn->executeStatement(
            'DELETE pcp FROM piste_condition_partage pcp JOIN piste p ON pcp.piste_id = p.id
             JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        $conn->executeStatement(
            'DELETE pp FROM piste_partenaire pp JOIN piste p ON pp.piste_id = p.id
             JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        foreach ([
            'reversement_retro_agent', 'condition_partage', 'avenant', 'revenu_pour_courtier',
            'type_revenu', 'chargement_pour_prime', 'cotation', 'piste', 'client', 'partenaire',
            'risque', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    // ===================== 1. L'assiette =====================

    public function testLAssietteEstCeQuiResteAuCabinetApresLesPartenaires(): void
    {
        $ids = $this->semer([['Alice', 10.0]], avecPartenaire: true);
        $cotation = $this->em()->getRepository(Cotation::class)->find($ids['cotationId']);

        $commissionPure = $this->helper()->getCotationMontantCommissionPure($cotation, -1, false);
        $retroPartenaire = $this->helper()->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1);

        self::assertGreaterThan(0.0, $retroPartenaire, 'Le partenaire doit bien toucher quelque chose.');
        self::assertSame(
            round($commissionPure - $retroPartenaire, 2),
            round($this->helper()->getCotationAssietteRetroAgent($cotation), 2),
            'L\'assiette de l\'agent est la commission pure MOINS la rétro du partenaire.',
        );

        // 10 % de cette assiette-là, et non 10 % de la commission brute.
        self::assertSame(
            round(($commissionPure - $retroPartenaire) * 0.10, 2),
            round($this->helper()->getCotationMontantRetroAgent($cotation), 2),
        );
    }

    public function testLeTauxEstLuEnPointsEtNonEnFraction(): void
    {
        // 25 dans le champ = 25 %, jamais 2 500 %. La conversion passe par getFraction(),
        // source unique de la convention du projet.
        $ids = $this->semer([['Alice', 25.0]]);
        $cotation = $this->em()->getRepository(Cotation::class)->find($ids['cotationId']);

        $assiette = $this->helper()->getCotationAssietteRetroAgent($cotation);
        self::assertSame(
            round($assiette * 0.25, 2),
            round($this->helper()->getCotationMontantRetroAgent($cotation), 2),
        );
    }

    // ===================== 2. Plusieurs agents =====================

    public function testDeuxAgentsSePartagentLaMemeAssietteEtSAdditionnent(): void
    {
        $ids = $this->semer([['Alice', 10.0], ['Bruno', 15.0]]);
        $em = $this->em();
        $cotation = $em->getRepository(Cotation::class)->find($ids['cotationId']);

        $assiette = $this->helper()->getCotationAssietteRetroAgent($cotation);
        $alice = $em->getRepository(Invite::class)->find($ids['agentIds']['Alice']);
        $bruno = $em->getRepository(Invite::class)->find($ids['agentIds']['Bruno']);

        $partAlice = round($this->helper()->getCotationMontantRetroAgent($cotation, $alice), 2);
        $partBruno = round($this->helper()->getCotationMontantRetroAgent($cotation, $bruno), 2);

        // Chacun sur l'assiette PLEINE : la part de Bruno ne se calcule pas sur ce qui
        // reste après Alice, sans quoi l'ordre de parcours changerait les montants.
        self::assertSame(round($assiette * 0.10, 2), $partAlice);
        self::assertSame(round($assiette * 0.15, 2), $partBruno);

        self::assertSame(
            round($partAlice + $partBruno, 2),
            round($this->helper()->getCotationMontantRetroAgent($cotation), 2),
            'Sans agent ciblé, les parts de tous les agents s\'additionnent.',
        );
    }

    public function testDeuxConditionsDuMemeAgentNeSeCumulentPas(): void
    {
        // Alice porte deux conditions applicables : c'est la PREMIÈRE qui l'emporte, comme
        // pour la cascade des partenaires. Les cumuler doublerait sa rémunération sur un
        // paramétrage redondant plutôt que de le signaler.
        $ids = $this->semer([['Alice', 10.0], ['Alice', 30.0]]);
        $cotation = $this->em()->getRepository(Cotation::class)->find($ids['cotationId']);

        $assiette = $this->helper()->getCotationAssietteRetroAgent($cotation);
        $montant = round($this->helper()->getCotationMontantRetroAgent($cotation), 2);

        self::assertNotSame(round($assiette * 0.40, 2), $montant, 'Les deux conditions ne se cumulent pas.');
        self::assertSame(round($assiette * 0.10, 2), $montant, 'C\'est la première applicable qui s\'applique.');
        self::assertCount(1, $this->helper()->getCotationConditionsAgent($cotation), 'Une seule condition par agent.');
    }

    // ===================== 3. Les cas nuls =====================

    public function testSansConditionDAgentIlNYAAucuneRetroInterne(): void
    {
        $ids = $this->semer([]);
        $cotation = $this->em()->getRepository(Cotation::class)->find($ids['cotationId']);

        self::assertSame(0.0, $this->helper()->getCotationMontantRetroAgent($cotation));
        self::assertSame([], $this->helper()->getCotationConditionsAgent($cotation));
    }

    public function testUneConditionQuiNeVisePasLeRisqueNeSApplique(): void
    {
        // Ciblage « inclure ces risques » sans aucun risque coché : forme neutre, jamais
        // satisfaite (c'est celle que la reconduction emploie pour neutraliser sans perdre).
        $ids = $this->semer([['Alice', 10.0]], critereRisque: ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES);
        $cotation = $this->em()->getRepository(Cotation::class)->find($ids['cotationId']);

        self::assertSame(0.0, $this->helper()->getCotationMontantRetroAgent($cotation));
    }

    public function testUnePropositionNonSouscriteNAlimenteAucunAgregat(): void
    {
        // Règle transversale du projet : tant qu'aucun avenant n'existe, l'affaire n'est
        // qu'un projet — ses montants sont des projections, jamais des agrégats.
        $ids = $this->semer([['Alice', 10.0]], souscrite: false);
        $em = $this->em();
        $entreprise = $em->getRepository(Entreprise::class)->find($ids['entrepriseId']);
        $alice = $em->getRepository(Invite::class)->find($ids['agentIds']['Alice']);

        $stats = $this->helper()->getIndicateursGlobaux($entreprise, true, ['agentCible' => $alice]);

        self::assertSame(0.0, round($stats['retro_commission_agent'], 2));
    }

    // ===================== 4. La réserve et l'exigibilité =====================

    public function testLaReserveDuCabinetBaisseDuMontantRetrocedeALAgent(): void
    {
        $ids = $this->semer([['Alice', 30.0]], avecPartenaire: true);
        $em = $this->em();
        $cotation = $em->getRepository(Cotation::class)->find($ids['cotationId']);
        $entreprise = $em->getRepository(Entreprise::class)->find($ids['entrepriseId']);

        $commissionPure = $this->helper()->getCotationMontantCommissionPure($cotation, -1, false);
        $retroPartenaire = $this->helper()->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1);
        $retroAgent = $this->helper()->getCotationMontantRetroAgent($cotation);

        $stats = $this->helper()->getIndicateursGlobaux($entreprise, true, ['cotationCible' => $cotation]);

        self::assertSame(
            Reserve::calculer($commissionPure, $retroPartenaire, $retroAgent),
            round($stats['reserve'], 2),
        );
        // Et elle a bien baissé par rapport à la réserve d'avant les agents.
        self::assertLessThan(
            Reserve::calculer($commissionPure, $retroPartenaire),
            round($stats['reserve'], 2),
        );
    }

    public function testUnCumulDeTauxSuperieurACentRendLaReserveNegativeSansEcretage(): void
    {
        // Deux agents à 60 % : le cabinet promet 120 % de ce qui lui reste. On ne masque
        // pas le paramétrage fautif derrière un plancher à zéro.
        $ids = $this->semer([['Alice', 60.0], ['Bruno', 60.0]]);
        $em = $this->em();
        $cotation = $em->getRepository(Cotation::class)->find($ids['cotationId']);
        $entreprise = $em->getRepository(Entreprise::class)->find($ids['entrepriseId']);

        $stats = $this->helper()->getIndicateursGlobaux($entreprise, true, ['cotationCible' => $cotation]);

        self::assertLessThan(0.0, round($stats['reserve'], 2), 'La réserve déficitaire reste visible.');
    }

    public function testLaRetroNEstExigibleQueLaCommissionEncaissee(): void
    {
        $ids = $this->semer([['Alice', 10.0]]);
        $em = $this->em();
        $avenant = $em->getRepository(Avenant::class)->find($ids['avenantId']);

        // Aucune commission encaissée (aucune note réglée) : le dû existe, l'exigible non.
        self::assertGreaterThan(0.0, $this->helper()->getAvenantMontantRetroAgent($avenant));
        self::assertSame(
            0.0,
            $this->helper()->getAvenantRetroAgentExigible($avenant),
            'Tant que le cabinet n\'a pas encaissé sa commission, rien n\'est réclamable par l\'agent.',
        );
    }

    public function testLeSoldeNeDevientJamaisNegatifApresUnTropVerse(): void
    {
        $ids = $this->semer([['Alice', 10.0]]);
        $em = $this->em();
        $avenant = $em->getRepository(Avenant::class)->find($ids['avenantId']);
        $alice = $em->getRepository(Invite::class)->find($ids['agentIds']['Alice']);

        $du = $this->helper()->getAvenantMontantRetroAgent($avenant, $alice);
        $this->verser($ids, 'Alice', $du * 2);

        $em->clear();
        $avenant = $em->getRepository(Avenant::class)->find($ids['avenantId']);
        $alice = $em->getRepository(Invite::class)->find($ids['agentIds']['Alice']);

        self::assertSame(
            0.0,
            $this->helper()->getAvenantRetroAgentSolde($avenant, $alice),
            'Un trop-versé n\'est pas une dette négative de l\'agent envers le cabinet.',
        );
    }

    public function testLeVerseSeLitDirectementSansProrataDeNote(): void
    {
        $ids = $this->semer([['Alice', 10.0]]);
        $this->verser($ids, 'Alice', 42.0);

        $em = $this->em();
        $em->clear();
        $avenant = $em->getRepository(Avenant::class)->find($ids['avenantId']);
        $alice = $em->getRepository(Invite::class)->find($ids['agentIds']['Alice']);

        self::assertSame(42.0, round($this->helper()->getAvenantMontantRetroAgentReversee($avenant, $alice), 2));
    }

    // ===================== Semis =====================

    /** Enregistre un reversement à un agent au titre de l'avenant semé. */
    private function verser(array $ids, string $prenomAgent, float $montant): void
    {
        $em = $this->em();
        $reversement = new \App\Entity\ReversementRetroAgent();
        $reversement->setAgent($em->getRepository(Invite::class)->find($ids['agentIds'][$prenomAgent]))
            ->setAvenant($em->getRepository(Avenant::class)->find($ids['avenantId']))
            ->setMontant(round($montant, 2))
            ->setPaidAt(new \DateTimeImmutable('-1 day'))
            ->setReference('RETRO-TEST-' . $prenomAgent);
        $reversement->setEntreprise($em->getRepository(Entreprise::class)->find($ids['entrepriseId']));
        $em->persist($reversement);
        $em->flush();
    }

    /**
     * Une affaire, éventuellement partagée avec un partenaire externe, portant les
     * conditions d'agent demandées.
     *
     * @param array<int, array{0:string, 1:float}> $agents couples [prénom, taux en POINTS]
     *
     * @return array{entrepriseId:int, cotationId:int, avenantId:int, agentIds:array<string,int>}
     */
    private function semer(
        array $agents,
        bool $avecPartenaire = false,
        bool $souscrite = true,
        int $critereRisque = ConditionPartage::CRITERE_PAS_RISQUES_CIBLES,
    ): array {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('RetroAgent Owner')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        // Le GESTIONNAIRE de l'affaire — délibérément distinct des agents bénéficiaires.
        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $risque = (new Risque())->setCode('RA')->setNomComplet('Risque Agent')->setDescription('Risque de l\'agent')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $client = (new Client())->setNom('Client Agent')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste Agent')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque agent')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($gestionnaire);
        $em->persist($piste);

        if ($avecPartenaire) {
            $partenaire = (new Partenaire())->setNom('Partenaire Externe')->setPart(self::PART_PARTENAIRE);
            $partenaire->setEntreprise($entreprise);
            $em->persist($partenaire);
            $piste->addPartenaire($partenaire);
        }

        $agentIds = [];
        foreach ($agents as [$prenom, $taux]) {
            if (!isset($agentIds[$prenom])) {
                $agent = (new Invite())->setNom($prenom)->setProprietaire(false);
                $agent->setEntreprise($entreprise);
                $em->persist($agent);
                $agentIds[$prenom] = $agent;
            }

            $condition = (new ConditionPartage())->setNom('Prime ' . $prenom)
                ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
                ->setTaux($taux)
                ->setCritereRisque($critereRisque)
                ->setAgent($agentIds[$prenom]);
            $condition->setEntreprise($entreprise);
            $em->persist($condition);

            $piste->addConditionsPartageAgent($condition);
        }

        $cotation = (new Cotation())->setNom('Cotation Agent')->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
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

        $avenantId = null;
        if ($souscrite) {
            $avenant = (new Avenant())->setReferencePolice('POL-AGENT')->setNumero('0')
                ->setDescription('Police agent')
                ->setStartingAt(new \DateTimeImmutable('-30 days'))
                ->setEndingAt(new \DateTimeImmutable('+335 days'));
            $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
            $cotation->addAvenant($avenant);
            $em->persist($avenant);
        }

        $em->flush();
        $ids = [
            'entrepriseId' => (int) $entreprise->getId(),
            'cotationId'   => (int) $cotation->getId(),
            'avenantId'    => $souscrite ? (int) $avenant->getId() : 0,
            'agentIds'     => array_map(static fn (Invite $i) => (int) $i->getId(), $agentIds),
        ];
        $em->clear();

        return $ids;
    }
}
