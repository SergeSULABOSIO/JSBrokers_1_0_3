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
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Canvas\Indicator\RevenuPourCourtierIndicatorStrategy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UNE CONDITION D'AGENT NE PAIE PAS UN PARTENAIRE.
 *
 * Les conditions propres à une affaire accueillent les deux natures de bénéficiaire : un
 * intermédiaire externe, ou un agent du cabinet. La cascade qui calcule la part du PARTENAIRE
 * les parcourait pourtant sans distinguer, et retenait la première applicable au risque.
 *
 * Une ligne « Alice 15 % » devenait donc candidate : elle masquait la condition du partenaire
 * et le payait au taux d'Alice — puis elle était recomptée par la cascade agent, qui lit
 * délibérément la même collection. La même ligne alimentait deux circuits d'argent, et les
 * deux étaient faux : le partenaire recevait le taux d'un autre, et le total sortant du
 * cabinet dépassait ce qui avait été convenu.
 *
 * Rien ne le signalait : les deux montants s'affichaient, plausibles, chacun à sa place.
 */
class ConditionAgentNePaiePasLePartenaireTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-fuitetaux@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit FuiteTaux SARL';

    private const COMMISSION = 1000.0;
    private const PART_PARTENAIRE = 5.0;   // POINTS — le taux habituel de l'intermédiaire
    private const TAUX_AGENT = 40.0;       // POINTS — volontairement très écarté, pour que
                                           //          toute confusion se voie dans le montant
    private const TAUX_PARTENAIRE = 12.0;  // POINTS — une vraie condition d'intermédiaire

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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :m', ['m' => self::OWNER_EMAIL]);
        $conn->executeStatement(
            'DELETE l FROM condition_partage_risque l JOIN condition_partage c ON l.condition_partage_id = c.id
             JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
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
        $this->em()->clear();
    }

    /**
     * Sème une affaire complète, et lui pose la condition demandée.
     *
     * @param 'agent'|'partenaire'|'aucune' $natureCondition ce qu'on met dans les conditions
     *                                                       PROPRES À L'AFFAIRE
     * @return array{revenuId:int, agentId:int, cotationId:int}
     */
    private function semer(string $natureCondition): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Fuite')->setVerified(true)->setPassword('x');
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

        $risque = (new Risque())->setCode('RC')->setNomComplet('Risque Fuite')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $partenaire = (new Partenaire())->setNom('Intermédiaire Fuite')->setPart(self::PART_PARTENAIRE);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $client = (new Client())->setNom('Client Fuite')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Piste Fuite')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Risque fuite')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $piste->setPartenaire($partenaire);
        $em->persist($piste);

        if ($natureCondition !== 'aucune') {
            $condition = (new ConditionPartage())
                ->setNom($natureCondition === 'agent' ? 'Part d\'Alice sur cette affaire' : 'Taux négocié sur cette affaire')
                ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
                ->setTaux($natureCondition === 'agent' ? self::TAUX_AGENT : self::TAUX_PARTENAIRE)
                ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
                ->setPiste($piste);
            $natureCondition === 'agent'
                ? $condition->setAgent($agent)
                : $condition->setPartenaire($partenaire);
            $condition->setEntreprise($entreprise);
            $em->persist($condition);
        }

        $cotation = (new Cotation())->setNom('Cotation Fuite')->setDuree(365);
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

        $avenant = (new Avenant())->setReferencePolice('POL-FUITE')->setNumero('0')
            ->setDescription('Police fuite')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))
            ->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($entreprise)->setInvite($invite);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $em->flush();
        $ids = [
            'revenuId' => (int) $revenu->getId(),
            'agentId' => (int) $agent->getId(),
            'cotationId' => (int) $cotation->getId(),
        ];
        $em->clear();

        return $ids;
    }

    private function strategie(): RevenuPourCourtierIndicatorStrategy
    {
        return static::getContainer()->get(RevenuPourCourtierIndicatorStrategy::class);
    }

    public function testLeTauxDeLAgentNeSertPlusAPayerLePartenaire(): void
    {
        $ids = $this->semer('agent');
        $revenu = $this->em()->getRepository(RevenuPourCourtier::class)->find($ids['revenuId']);

        // LE CŒUR DU CORRECTIF : la part du partenaire suit SON taux habituel (5 %), et non
        // les 40 % négociés pour Alice. L'écart est volontairement large pour qu'aucune
        // confusion ne puisse passer pour un arrondi.
        self::assertSame(
            self::PART_PARTENAIRE / 100.0,
            $this->strategie()->getRevenuPartPartenaire($revenu),
            'Une condition d\'agent ne doit jamais fixer le taux versé à l\'intermédiaire.',
        );
    }

    public function testUneConditionDAgentNEstPlusRetenueParLaCascadePartenaire(): void
    {
        $ids = $this->semer('agent');
        $revenu = $this->em()->getRepository(RevenuPourCourtier::class)->find($ids['revenuId']);

        self::assertNull(
            $this->strategie()->conditionRetenue($revenu),
            'Aucune condition ne doit être retenue : la seule présente vise un agent.',
        );
    }

    public function testLaRetrocommissionDeLAgentResteDue(): void
    {
        $ids = $this->semer('agent');
        $em = $this->em();
        $helper = static::getContainer()->get(IndicatorCalculationHelper::class);

        $cotation = $em->getRepository(Cotation::class)->find($ids['cotationId']);
        $agent = $em->getRepository(Invite::class)->find($ids['agentId']);

        // Écarter la condition de la cascade PARTENAIRE ne doit pas l'écarter de la sienne :
        // c'est bien une part d'agent, elle reste due. Sans cette assertion, le correctif
        // aurait pu « régler » le problème en faisant disparaître la rétrocommission.
        self::assertGreaterThan(
            0.0,
            $helper->getCotationMontantRetroAgent($cotation, $agent),
            'La part d\'Alice reste due — elle change seulement de circuit.',
        );
    }

    public function testUneConditionDIntermediaireLEmporteToujours(): void
    {
        $ids = $this->semer('partenaire');
        $revenu = $this->em()->getRepository(RevenuPourCourtier::class)->find($ids['revenuId']);

        // Non-régression : le mécanisme « la condition de l'affaire remplace le taux habituel »
        // continue de fonctionner, dès lors que la condition vise bien l'intermédiaire.
        self::assertSame(
            self::TAUX_PARTENAIRE / 100.0,
            $this->strategie()->getRevenuPartPartenaire($revenu),
            'Une condition d\'intermédiaire propre à l\'affaire doit primer sur son taux habituel.',
        );
    }

    public function testSansConditionLeTauxHabituelSApplique(): void
    {
        $ids = $this->semer('aucune');
        $revenu = $this->em()->getRepository(RevenuPourCourtier::class)->find($ids['revenuId']);

        self::assertSame(
            self::PART_PARTENAIRE / 100.0,
            $this->strategie()->getRevenuPartPartenaire($revenu),
        );
    }
}
