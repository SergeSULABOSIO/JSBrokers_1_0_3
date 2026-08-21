<?php

namespace App\Tests\Services;

use App\Entity\Article;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Service\Partage\Reserve;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Canvas\Indicator\RevenuPourCourtierIndicatorStrategy;
use App\Services\DashboardDataProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE MÊME ARGENT DOIT DIRE LA MÊME CHOSE, D'UN ÉCRAN À L'AUTRE.
 *
 * Deux écrans annonçaient des montants que le reste du logiciel ne confirmait pas. Aucun
 * des deux ne levait d'erreur : ils affichaient un chiffre plausible, à côté d'un autre
 * chiffre plausible, et rien ne disait lequel croire.
 *
 *  ① LE TABLEAU DE BORD appliquait la part HABITUELLE du partenaire — Partenaire::getFraction()
 *    en dur — au lieu du taux réellement retenu par la cascade. Dès qu'une condition de
 *    partage existait sur l'affaire, sa rétrocommission divergeait de celle de la fiche, de
 *    la cotation et de l'assistant. Et sous un seuil non franchi, il annonçait une
 *    rétrocommission là où le moteur n'en devait aucune.
 *
 *  ② LA FICHE D'UN REVENU calculait sa réserve à DEUX termes — commission pure moins part
 *    du partenaire — en oubliant la part des agents internes, que la fiche de l'avenant,
 *    elle, déduisait. La réserve la plus flatteuse s'affichait au plus près de la saisie.
 *
 * Le décor est choisi pour que toute confusion se VOIE : la part habituelle du partenaire
 * (5 %) est très éloignée du taux négocié sur l'affaire (40 %).
 */
class RetroCoherenceEcransTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-coherence-retro@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit CoherenceRetro SARL';

    private const COMMISSION = 1000.0;
    private const PART_PAR_DEFAUT = 5.0;
    private const TAUX_NEGOCIE = 40.0;
    private const TAUX_AGENT = 10.0;

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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        foreach ([
            'paiement', 'article', 'note', 'avenant', 'revenu_pour_courtier',
            'chargement_pour_prime', 'tranche', 'cotation', 'condition_partage', 'piste',
            'client', 'partenaire', 'assureur', 'risque', 'type_revenu', 'invite', 'taxe',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n",
                ['n' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * Une affaire souscrite, encaissée cette année, portant : un intermédiaire à 5 % par
     * défaut, une condition PROPRE à l'affaire qui lui accorde 40 %, et un agent interne
     * à 10 % du reliquat.
     *
     * @param bool $avecConditionNegociee false : aucune condition, la part habituelle
     *                                    s'applique — le cas où les deux écrans devaient
     *                                    déjà s'accorder
     *
     * @return array{entrepriseId:int, revenuId:int, cotationId:int}
     */
    private function semer(bool $avecConditionNegociee = true): array
    {
        $em = $this->em();
        $annee = (int) date('Y');

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Coherence')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($invite);

        $agent = (new Invite())->setNom('Alice Agent')->setProprietaire(false);
        $agent->setEntreprise($entreprise);
        $em->persist($agent);

        $assureur = (new Assureur())->setNom('Assureur Cohérence')->setEmail('assureur-coherence@test.local')
            ->setNumimpot('IMP-A')->setIdnat('IDNAT-A')->setRccm('RCCM-A');
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        $risque = (new Risque())->setCode('RC')->setNomComplet('Risque Cohérence')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $partenaire = (new Partenaire())->setNom('Intermédiaire Cohérence')->setPart(self::PART_PAR_DEFAUT);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $client = (new Client())->setNom('Client Cohérence')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste Cohérence')->setTypeAvenant(0)
            ->setDescriptionDuRisque('x')->setExercice($annee)
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $piste->setPartenaire($partenaire);
        $em->persist($piste);

        if ($avecConditionNegociee) {
            $negociee = (new ConditionPartage())->setNom('Taux négocié sur cette affaire')
                ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
                ->setTaux(self::TAUX_NEGOCIE)
                ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
                ->setPartenaire($partenaire)
                ->setPiste($piste);
            $negociee->setEntreprise($entreprise);
            $em->persist($negociee);
        }

        $partAgent = (new ConditionPartage())->setNom('Part d\'Alice')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(self::TAUX_AGENT)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($agent);
        $partAgent->setEntreprise($entreprise);
        $piste->addConditionsPartageAgent($partAgent);
        $em->persist($partAgent);

        $cotation = (new Cotation())->setNom('Cotation Cohérence')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($entreprise);
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

        // Une tranche unique portant 100 % de l'affaire : sans elle, l'article d'une note
        // de commission vaut ZÉRO — son montant se dérive du revenu ET de la tranche — et
        // le tableau de production resterait vide, ce qui ne prouverait rien.
        $tranche = (new Tranche())->setNom('Tranche unique')->setPourcentage(100.0)
            ->setPayableAt(new \DateTimeImmutable('-30 days'))
            ->setEcheanceAt(new \DateTimeImmutable('+30 days'));
        $tranche->setCotation($cotation);
        $tranche->setEntreprise($entreprise);
        $em->persist($tranche);

        $avenant = (new Avenant())->setReferencePolice('POL-COH')->setNumero('0')
            ->setDescription('Police cohérence')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))
            ->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($entreprise)->setInvite($invite);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        // La commission est FACTURÉE à l'assureur et ENCAISSÉE cette année : c'est ce qui
        // la fait entrer dans le tableau de production.
        $note = (new Note())->setNom('Note commission Cohérence')->setType(0)
            ->setAddressedTo(Note::TO_ASSUREUR)->setReference('N-COH-1')
            ->setValidated(true)->setSignature('')
            ->setAssureur($assureur);
        $note->setEntreprise($entreprise);
        $em->persist($note);

        $article = (new Article())->setNote($note)->setRevenuFacture($revenu)->setTranche($tranche);
        $article->setEntreprise($entreprise);
        $em->persist($article);

        $encaissement = (new Paiement())->setMontant(self::COMMISSION)->setReference('ENC-COH-1')
            ->setPaidAt(new \DateTimeImmutable($annee . '-06-15 10:00:00'))
            ->setNote($note);
        $encaissement->setEntreprise($entreprise);
        $em->persist($encaissement);

        $em->flush();
        $ids = [
            'entrepriseId' => (int) $entreprise->getId(),
            'revenuId'     => (int) $revenu->getId(),
            'cotationId'   => (int) $cotation->getId(),
        ];
        $em->clear();

        return $ids;
    }

    /** La rétrocommission totale du tableau de production, tous mois confondus. */
    private function retroDuTableauDeBord(int $entrepriseId): float
    {
        $entreprise = $this->em()->getRepository(Entreprise::class)->find($entrepriseId);
        $table = static::getContainer()->get(DashboardDataProvider::class)
            ->getProductionTableData($entreprise, (int) date('Y'));

        $total = 0.0;
        foreach ($table['monthTotals'] ?? [] as $mois) {
            $total += (float) ($mois['retrocommission'] ?? 0.0);
        }

        return round($total, 2);
    }

    // ===================== ① Le tableau de bord =====================

    public function testLeTableauDeBordSuitLeTauxNEGOCIEEtNonLaPartHabituelle(): void
    {
        $ids = $this->semer();

        $helper = static::getContainer()->get(IndicatorCalculationHelper::class);
        $cotation = $this->em()->getRepository(Cotation::class)->find($ids['cotationId']);
        $attendue = round($helper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1), 2);

        // La source unique dit 40 % de la commission pure.
        self::assertEqualsWithDelta(
            self::COMMISSION * self::TAUX_NEGOCIE / 100,
            $attendue,
            0.01,
            'Le moteur applique bien la condition propre à l\'affaire.',
        );

        // LE TABLEAU DE BORD DOIT DIRE LA MÊME CHOSE. Avant, il annonçait 5 % — la part
        // habituelle de l'intermédiaire — soit huit fois moins, sans que rien ne l'explique.
        self::assertEqualsWithDelta($attendue, $this->retroDuTableauDeBord($ids['entrepriseId']), 0.01);
    }

    public function testSansConditionLesDeuxEcransSAccordentToujours(): void
    {
        $ids = $this->semer(false);

        $helper = static::getContainer()->get(IndicatorCalculationHelper::class);
        $cotation = $this->em()->getRepository(Cotation::class)->find($ids['cotationId']);
        $attendue = round($helper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1), 2);

        // Le repli sur la part habituelle reste le comportement attendu : la correction ne
        // devait rien changer au cas nominal.
        self::assertEqualsWithDelta(self::COMMISSION * self::PART_PAR_DEFAUT / 100, $attendue, 0.01);
        self::assertEqualsWithDelta($attendue, $this->retroDuTableauDeBord($ids['entrepriseId']), 0.01);
    }

    // ===================== ② La réserve de la fiche Revenu =====================

    public function testLaFicheDUnRevenuDeduitLaPartDesAgents(): void
    {
        $ids = $this->semer();

        $helper = static::getContainer()->get(IndicatorCalculationHelper::class);
        $revenu = $this->em()->getRepository(RevenuPourCourtier::class)->find($ids['revenuId']);

        $pure = $helper->getRevenuMontantPure($revenu);
        $retroPartenaire = $helper->getRevenuMontantRetrocommissionsPayableParCourtier($revenu, null, -1);
        $retroAgent = $helper->getRevenuMontantRetroAgent($revenu);

        // L'agent partage le RELIQUAT : 10 % de ce qui reste après les 40 % du partenaire.
        self::assertEqualsWithDelta(
            ($pure - $retroPartenaire) * self::TAUX_AGENT / 100,
            $retroAgent,
            0.01,
            'La part de l\'agent se calcule sur le reliquat, jamais sur la pure.',
        );
        self::assertGreaterThan(0.0, $retroAgent, 'Le décor doit réellement rémunérer un agent.');

        $fiche = static::getContainer()->get(RevenuPourCourtierIndicatorStrategy::class)->calculate($revenu);

        self::assertEqualsWithDelta(
            Reserve::calculer($pure, $retroPartenaire, $retroAgent),
            (float) $fiche['reserve'],
            0.01,
            'La réserve du revenu déduit les TROIS termes.',
        );

        // Et elle est bien PLUS BASSE qu'avant : la formule à deux termes annonçait au
        // cabinet un argent qu'il ne gardait pas.
        self::assertLessThan(
            Reserve::calculer($pure, $retroPartenaire),
            (float) $fiche['reserve'],
        );
    }

    public function testLaFicheDUnRevenuMONTRECeQuiPartChezLesAgents(): void
    {
        $ids = $this->semer();

        $revenu = $this->em()->getRepository(RevenuPourCourtier::class)->find($ids['revenuId']);
        $fiche = static::getContainer()->get(RevenuPourCourtierIndicatorStrategy::class)->calculate($revenu);

        // Une réserve qui baisse sans cause visible se lit comme une erreur de calcul :
        // le montant qui l'explique doit figurer sur la même fiche.
        self::assertArrayHasKey('retroAgentDue', $fiche);
        self::assertEqualsWithDelta(
            static::getContainer()->get(IndicatorCalculationHelper::class)->getRevenuMontantRetroAgent($revenu),
            (float) $fiche['retroAgentDue'],
            0.01,
        );
    }

    public function testLaPartDAgentDUnRevenuSeSommeEnCelleDeLaCotation(): void
    {
        $ids = $this->semer();

        $helper = static::getContainer()->get(IndicatorCalculationHelper::class);
        $cotation = $this->em()->getRepository(Cotation::class)->find($ids['cotationId']);

        $parRevenu = 0.0;
        foreach ($cotation->getRevenus() as $revenu) {
            $parRevenu += $helper->getRevenuMontantRetroAgent($revenu);
        }

        // Le prorata d'assiette est EXACT : répartir puis resommer ne perd rien. Sans quoi
        // la fiche du revenu et celle de l'avenant se contrediraient à leur tour.
        self::assertEqualsWithDelta(
            $helper->getCotationMontantRetroAgent($cotation),
            $parRevenu,
            0.01,
        );
    }
}
