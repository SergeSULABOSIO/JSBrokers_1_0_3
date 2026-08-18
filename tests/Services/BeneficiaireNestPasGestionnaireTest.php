<?php

namespace App\Tests\Services;

use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE BÉNÉFICIAIRE N'EST PAS LE GESTIONNAIRE.
 *
 * Un agent peut décrocher le premier rendez-vous puis confier la gestion quotidienne de
 * l'affaire à un collègue : il reste le bénéficiaire de la rétrocommission. Les deux
 * rôles sont donc portés par deux champs sans aucun lien :
 *
 *   GESTIONNAIRE : Piste::invite (posé par AuditableTrait à la saisie)  → filtre inviteCible
 *   BÉNÉFICIAIRE : ConditionPartage::agent, via Piste::conditionsPartageAgent → filtre agentCible
 *
 * Les confondre — en dérivant l'un de l'autre, ou en réutilisant un filtre pour l'autre —
 * attribuerait la rémunération à la mauvaise personne. Ce test rend cette confusion
 * impossible sans casser la suite.
 *
 * Le décor : Gaston GÈRE l'affaire, Alice en est la BÉNÉFICIAIRE. Aucun des deux n'est
 * l'autre.
 */
class BeneficiaireNestPasGestionnaireTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-beneficiaire-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Beneficiaire SARL';

    private const COMMISSION = 800.0;
    private const TAUX_ALICE = 25.0; // POINTS

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
        foreach ([
            'condition_partage', 'avenant', 'revenu_pour_courtier', 'type_revenu',
            'chargement_pour_prime', 'cotation', 'piste', 'client', 'risque', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    public function testLeGestionnairePorteLaProductionMaisAucuneRetro(): void
    {
        $ids = $this->semer();
        $em = $this->em();
        $entreprise = $em->getRepository(Entreprise::class)->find($ids['entrepriseId']);
        $gaston = $em->getRepository(Invite::class)->find($ids['gastonId']);

        $gere = $this->helper()->getIndicateursGlobaux($entreprise, true, ['inviteCible' => $gaston]);
        $percu = $this->helper()->getIndicateursGlobaux($entreprise, true, ['agentCible' => $gaston]);

        self::assertGreaterThan(0.0, $gere['prime_totale'], 'Gaston gère bien une affaire.');
        self::assertGreaterThan(0.0, $gere['commission_totale']);
        self::assertSame(
            0.0,
            round($percu['retro_commission_agent'], 2),
            'Gérer une affaire ne donne droit à aucune rétrocommission.',
        );
    }

    public function testLeBeneficiairePorteLaRetroMaisAucuneProduction(): void
    {
        $ids = $this->semer();
        $em = $this->em();
        $entreprise = $em->getRepository(Entreprise::class)->find($ids['entrepriseId']);
        $alice = $em->getRepository(Invite::class)->find($ids['aliceId']);

        $gere = $this->helper()->getIndicateursGlobaux($entreprise, true, ['inviteCible' => $alice]);
        $percu = $this->helper()->getIndicateursGlobaux($entreprise, true, ['agentCible' => $alice]);

        self::assertSame(0.0, round($gere['prime_totale'], 2), 'Alice ne gère aucune affaire.');
        self::assertGreaterThan(
            0.0,
            $percu['retro_commission_agent'],
            'Alice touche pourtant la rétrocommission de l\'affaire qu\'elle a apportée.',
        );
    }

    public function testLesDeuxFiltresNeSelectionnentPasLesMemesAffaires(): void
    {
        $ids = $this->semer();
        $em = $this->em();
        $entreprise = $em->getRepository(Entreprise::class)->find($ids['entrepriseId']);
        $gaston = $em->getRepository(Invite::class)->find($ids['gastonId']);
        $alice = $em->getRepository(Invite::class)->find($ids['aliceId']);

        // agentCible sur Gaston ne ramène RIEN (il n'est bénéficiaire de rien), alors que
        // inviteCible sur Gaston ramène l'affaire : les deux axes sont bien distincts.
        self::assertSame(0.0, round($this->helper()->getIndicateursGlobaux($entreprise, true, ['agentCible' => $gaston])['commission_totale'], 2));
        self::assertGreaterThan(0.0, $this->helper()->getIndicateursGlobaux($entreprise, true, ['inviteCible' => $gaston])['commission_totale']);

        // Et symétriquement pour Alice.
        self::assertGreaterThan(0.0, $this->helper()->getIndicateursGlobaux($entreprise, true, ['agentCible' => $alice])['commission_totale']);
        self::assertSame(0.0, round($this->helper()->getIndicateursGlobaux($entreprise, true, ['inviteCible' => $alice])['commission_totale'], 2));
    }

    public function testLaProductionDeLAgentSeMesureSurCeQuIlApporteNonSurCeQuIlGere(): void
    {
        // Une condition à seuil mesurée sur l'unité « production de l'agent ». Le seuil est
        // fixé JUSTE au-dessus de la commission de l'affaire d'Alice : s'il se mesurait sur
        // la production de GASTON (même affaire, même montant) le résultat serait le même —
        // on distingue donc en interrogeant l'agent qui ne porte rien.
        $ids = $this->semer(seuilAlice: self::COMMISSION + 1.0);
        $em = $this->em();
        $cotation = $em->getRepository(Cotation::class)->find($ids['cotationId']);

        self::assertSame(
            0.0,
            round($this->helper()->getCotationMontantRetroAgent($cotation), 2),
            'Au-dessus de sa production, le seuil n\'est pas franchi : rien n\'est dû.',
        );

        // Et sous le seuil, la condition se déclenche : le seuil est bien évalué, pas ignoré.
        $ids2 = $this->semer(seuilAlice: 1.0, suffixe: 'bis');
        $cotation2 = $this->em()->getRepository(Cotation::class)->find($ids2['cotationId']);
        self::assertGreaterThan(0.0, $this->helper()->getCotationMontantRetroAgent($cotation2));
    }

    /**
     * Gaston gère, Alice bénéficie.
     *
     * @param float|null $seuilAlice si fourni, la condition d'Alice devient une condition
     *                               à seuil mesurée sur SA production (unité agent)
     *
     * @return array{entrepriseId:int, cotationId:int, gastonId:int, aliceId:int}
     */
    private function semer(?float $seuilAlice = null, string $suffixe = ''): array
    {
        $em = $this->em();

        $owner = $em->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL]);
        if ($owner === null) {
            $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Beneficiaire Owner')->setVerified(true)->setPassword('x');
            $em->persist($owner);
        }

        $entreprise = $em->getRepository(Entreprise::class)->findOneBy(['nom' => self::ENTREPRISE_NOM]);
        if ($entreprise === null) {
            $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
                ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
            $entreprise->setUtilisateur($owner);
            $em->persist($entreprise);
        }

        $gaston = (new Invite())->setNom('Gaston le gestionnaire' . $suffixe)->setProprietaire(true);
        $gaston->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gaston);

        // Alice n'a AUCUNE piste à son nom : elle ne gère rien.
        $alice = (new Invite())->setNom('Alice l\'apporteuse' . $suffixe)->setProprietaire(false);
        $alice->setEntreprise($entreprise);
        $em->persist($alice);

        $risque = (new Risque())->setCode('RB' . $suffixe)->setNomComplet('Risque Bénéficiaire' . $suffixe)
            ->setDescription('Risque du bénéficiaire')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $client = (new Client())->setNom('Client Bénéficiaire' . $suffixe)->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste Bénéficiaire' . $suffixe)->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque bénéficiaire')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        // ⚠ Le gestionnaire, et lui seul, est l'invité de la piste.
        $piste->setEntreprise($entreprise)->setInvite($gaston);
        $em->persist($piste);

        $condition = (new ConditionPartage())->setNom('Prime apporteur Alice' . $suffixe)
            ->setTaux(self::TAUX_ALICE)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($alice);
        if ($seuilAlice !== null) {
            $condition->setFormule(ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL)
                ->setSeuil($seuilAlice)
                ->setUniteMesure(ConditionPartage::UNITE_SOMME_COMMISSION_PURE_AGENT);
        } else {
            $condition->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0);
        }
        $condition->setEntreprise($entreprise);
        $em->persist($condition);
        $piste->addConditionsPartageAgent($condition);

        $cotation = (new Cotation())->setNom('Cotation Bénéficiaire' . $suffixe)->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setEntreprise($entreprise);
        $em->persist($cotation);

        $chargement = (new ChargementPourPrime())->setNom('Prime' . $suffixe)->setMontantFlatExceptionel(4000.0);
        $chargement->setEntreprise($entreprise);
        $cotation->addChargement($chargement);
        $em->persist($chargement);

        $typeRevenu = (new TypeRevenu())->setNom('Commission' . $suffixe)->setMontantflat(self::COMMISSION)
            ->setShared(true)->setMultipayments(true)->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
        $typeRevenu->setEntreprise($entreprise);
        $em->persist($typeRevenu);

        $revenu = (new RevenuPourCourtier())->setNom('Revenu' . $suffixe)->setTypeRevenu($typeRevenu)->setCotation($cotation);
        $revenu->setEntreprise($entreprise);
        $em->persist($revenu);

        $avenant = (new Avenant())->setReferencePolice('POL-BENEF' . $suffixe)->setNumero('0')
            ->setDescription('Police bénéficiaire')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))
            ->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($entreprise)->setInvite($gaston);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $em->flush();
        $ids = [
            'entrepriseId' => (int) $entreprise->getId(),
            'cotationId'   => (int) $cotation->getId(),
            'gastonId'     => (int) $gaston->getId(),
            'aliceId'      => (int) $alice->getId(),
        ];
        $em->clear();

        return $ids;
    }
}
