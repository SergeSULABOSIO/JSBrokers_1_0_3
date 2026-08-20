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
use App\Services\Canvas\Indicator\ConditionPartageIndicatorStrategy;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UNE CONDITION MASQUÉE NE RÉTROCÈDE RIEN — ET NE DOIT DONC RIEN ANNONCER.
 *
 * Deux rattachements possibles pour une condition de partage : la PISTE (exceptionnelle)
 * et le PARTENAIRE (générale). Quand les deux existent et visent le même risque, celle de
 * la piste l'emporte — elle REMPLACE l'autre, elle ne s'y ajoute pas.
 *
 * Le chemin de l'argent respectait cette cascade. Les indicateurs d'impact de la rubrique
 * Condition de partage, eux, l'ignoraient : ils imputaient à CHAQUE condition la
 * rétrocommission qu'elle aurait produite seule. Le courtier lisait donc, sur la fiche de
 * la condition masquée, un « Total rétrocommission » et un « Nombre de dossiers
 * concernés » qui ne correspondaient à aucun franc versé — et la somme des deux fiches
 * dépassait ce qui sortait réellement de sa poche.
 *
 * Les deux consommateurs passent désormais par la même source : la cascade de
 * RevenuPourCourtierIndicatorStrategy::conditionRetenue().
 */
class ConditionPartageCascadeTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-cascade-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Cascade SARL';

    private const COMMISSION = 500.0;
    private const TAUX_PISTE = 40.0;       // POINTS — la condition qui l'emporte
    private const TAUX_PARTENAIRE = 10.0;  // POINTS — la condition masquée
    private const PART_PAR_DEFAUT = 5.0;   // POINTS — le taux nu du partenaire

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
        foreach ([
            'condition_partage', 'avenant', 'revenu_pour_courtier', 'type_revenu',
            'chargement_pour_prime', 'cotation', 'piste', 'client', 'partenaire', 'risque', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    public function testLaConditionDeLaPisteEstCelleQuiLEmporte(): void
    {
        $ids = $this->semer();
        $em = $this->em();
        $helper = static::getContainer()->get(IndicatorCalculationHelper::class);

        $revenu = $em->getRepository(RevenuPourCourtier::class)->find($ids['revenuId']);
        $retenue = $helper->conditionPartageRetenue($revenu);

        self::assertNotNull($retenue);
        self::assertSame(
            $ids['conditionPisteId'],
            (int) $retenue->getId(),
            'La condition portée par la PISTE prime sur celle du partenaire.',
        );
    }

    public function testLaConditionMasqueeNAnnoncePlusAucuneRetrocommission(): void
    {
        $ids = $this->semer();
        $em = $this->em();
        $helper = static::getContainer()->get(IndicatorCalculationHelper::class);
        $revenu = $em->getRepository(RevenuPourCourtier::class)->find($ids['revenuId']);

        $conditionMasquee = $em->getRepository(ConditionPartage::class)->find($ids['conditionPartenaireId']);
        $conditionRetenue = $em->getRepository(ConditionPartage::class)->find($ids['conditionPisteId']);

        self::assertSame(
            0.0,
            $helper->applyRevenuConditionsSpeciales($conditionMasquee, $revenu, -1),
            'La condition du partenaire est remplacée par celle de la piste : elle ne '
            . 'rétrocède rien, elle ne doit donc rien afficher.',
        );
        self::assertGreaterThan(
            0.0,
            $helper->applyRevenuConditionsSpeciales($conditionRetenue, $revenu, -1),
            'La condition qui l\'emporte, elle, produit bien une rétrocommission.',
        );
    }

    public function testLaFicheDeLaConditionMasqueeNAnnoncePlusDeDossierConcerne(): void
    {
        $ids = $this->semer();
        $em = $this->em();
        $strategie = static::getContainer()->get(ConditionPartageIndicatorStrategy::class);

        $masquee = $strategie->calculate($em->getRepository(ConditionPartage::class)->find($ids['conditionPartenaireId']));
        $retenue = $strategie->calculate($em->getRepository(ConditionPartage::class)->find($ids['conditionPisteId']));

        self::assertSame(0.0, $masquee['totalRetroCommission'], 'Fiche de la condition masquée : rien à annoncer.');
        self::assertSame(0, $masquee['nombreDossiersConcernes'], 'Aucun dossier n\'est concerné par une condition masquée.');

        self::assertGreaterThan(0.0, $retenue['totalRetroCommission']);
        self::assertSame(1, $retenue['nombreDossiersConcernes']);
    }

    public function testLaRetrocommissionDueSuitLeTauxDeLaConditionRetenue(): void
    {
        $ids = $this->semer();
        $em = $this->em();
        $helper = static::getContainer()->get(IndicatorCalculationHelper::class);
        $cotation = $em->getRepository(Cotation::class)->find($ids['cotationId']);

        // Ni le taux de la condition masquée (10 %), ni la part nue du partenaire (5 %) :
        // c'est bien le taux de la condition de la piste qui sort de la caisse.
        $commissionPure = $helper->getCotationMontantCommissionPure($cotation, -1, true);

        self::assertSame(
            round($commissionPure * self::TAUX_PISTE / 100, 2),
            round($helper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1), 2),
        );
    }

    /**
     * Un partenaire porteur d'une condition générale, une piste porteuse d'une condition
     * exceptionnelle, toutes deux applicables au risque et sans seuil : le cas exact où
     * l'une masque l'autre.
     *
     * @return array{revenuId:int, cotationId:int, conditionPisteId:int, conditionPartenaireId:int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Cascade Owner')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($invite);

        $risque = (new Risque())->setCode('RC')->setNomComplet('Risque Cascade')->setDescription('Risque de la cascade')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $partenaire = (new Partenaire())->setNom('Partenaire Cascade')->setPart(self::PART_PAR_DEFAUT);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $client = (new Client())->setNom('Client Cascade')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste Cascade')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque cascade')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $piste->setPartenaire($partenaire);
        $em->persist($piste);

        $conditionPartenaire = (new ConditionPartage())->setNom('Générale du partenaire')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(self::TAUX_PARTENAIRE)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setPartenaire($partenaire);
        $conditionPartenaire->setEntreprise($entreprise);
        $em->persist($conditionPartenaire);

        $conditionPiste = (new ConditionPartage())->setNom('Exceptionnelle de la piste')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(self::TAUX_PISTE)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setPiste($piste);
        $conditionPiste->setEntreprise($entreprise);
        $em->persist($conditionPiste);

        $cotation = (new Cotation())->setNom('Cotation Cascade')->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())->setNom('Prime')->setMontantFlatExceptionel(2000.0);
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

        $avenant = (new Avenant())->setReferencePolice('POL-CASCADE')->setNumero('0')
            ->setDescription('Police cascade')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))
            ->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($entreprise)->setInvite($invite);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $em->flush();
        $ids = [
            'revenuId'              => (int) $revenu->getId(),
            'cotationId'            => (int) $cotation->getId(),
            'conditionPisteId'      => (int) $conditionPiste->getId(),
            'conditionPartenaireId' => (int) $conditionPartenaire->getId(),
        ];
        $em->clear();

        return $ids;
    }
}
