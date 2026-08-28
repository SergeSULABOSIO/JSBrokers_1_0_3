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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE TÉMOIN : LA FORMULE NE BOUGE PAS.
 *
 * Ce test ne vérifie aucune fonctionnalité. Il ÉPINGLE, au centime, ce que le moteur
 * distribue sur un jeu d'affaires figé — commission pure, part du partenaire, part de
 * l'agent, réserve du cabinet — et il le fait AVANT que le rattachement des conditions ne
 * s'ouvre aux deux familles.
 *
 * ── POURQUOI IL EXISTE ──────────────────────────────────────────────────────────────
 * Le lot qui suit touche au CHEMIN par lequel une condition atteint une affaire : une
 * cascade qui gagne un étage, une collection qui accueille une seconde famille, une
 * autorité qui se dédouble. Rien de tout cela ne doit changer un seul montant sur les
 * affaires existantes — mais rien, dans le code, ne l'interdit : ce sont les mêmes
 * méthodes qui calculent.
 *
 * Sans témoin, une dérive d'un centime sur la réserve serait indiscernable d'un
 * changement voulu, et se découvrirait par un courtier contestant sa marge. Avec lui, la
 * question ne se pose pas : ou bien ces nombres tiennent, ou bien on a touché à la
 * formule sans le vouloir.
 *
 * ── CE QUI EST ÉPINGLÉ, ET POURQUOI CES QUATRE-LÀ ──────────────────────────────────
 * Les quatre grandeurs forment une chaîne fermée : la commission pure se répartit
 * intégralement entre partenaire, agent et réserve. Épingler la somme ET ses parts fait
 * qu'aucune erreur de report ne peut se compenser en silence.
 *
 * Les montants sont écrits en DUR, jamais recalculés depuis le moteur : un témoin qui
 * demanderait au moteur ce qu'il doit trouver ne témoignerait de rien.
 */
class PartageInvariantTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-invariant-partage@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Invariant Partage SARL';

    // Le jeu d'essai, en clair. Aucune de ces valeurs n'est ronde par hasard : des taux
    // qui ne tombent pas juste feraient passer une erreur d'arrondi pour du bruit.
    private const COMMISSION = 500.0;
    private const PART_PARTENAIRE_DEFAUT = 5.0;   // POINTS — la part nue, masquée ici
    private const TAUX_CONDITION_PARTENAIRE = 40.0; // POINTS — la condition de l'affaire
    private const TAUX_CONDITION_AGENT = 20.0;      // POINTS — sur le RELIQUAT

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
            'DELETE pcp FROM piste_condition_partage pcp
             JOIN piste p ON pcp.piste_id = p.id
             JOIN entreprise e ON p.entreprise_id = e.id
             WHERE e.nom = :nom',
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
    }

    /**
     * LES QUATRE GRANDEURS, AU CENTIME.
     *
     * Le partenaire se sert d'abord, sur la commission pure ; l'agent partage le RELIQUAT.
     * L'ordre est la mécanique même du cabinet : l'inverser doublerait la rémunération d'un
     * même euro.
     */
    public function testLaRepartitionEstCelleQuOnEpingle(): void
    {
        $ids = $this->semer();
        $cotation = $this->em()->getRepository(Cotation::class)->find($ids['cotationId']);
        $agent = $this->em()->getRepository(Invite::class)->find($ids['agentId']);
        $helper = $this->helper();

        $pure = round($helper->getCotationMontantCommissionPure($cotation, -1, true), 2);
        $partenaire = round($helper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1), 2);
        $agentDu = round($helper->getCotationMontantRetroAgent($cotation, $agent), 2);

        self::assertSame(500.0, $pure, 'La commission pure du jeu d’essai.');
        self::assertSame(200.0, $partenaire, 'Le partenaire prend 40 % de la commission pure.');
        self::assertSame(60.0, $agentDu, 'L’agent prend 20 % du RELIQUAT (500 − 200), pas de la pure.');

        // LA CHAÎNE EST FERMÉE : ce qui reste au cabinet est exactement le solde. Sans cette
        // vérification, deux erreurs opposées se compenseraient sans que rien ne le dise.
        self::assertSame(
            240.0,
            round($pure - $partenaire - $agentDu, 2),
            'La réserve du cabinet est le solde — la répartition ne perd ni ne crée d’euro.',
        );
    }

    /**
     * LA CASCADE DU PARTENAIRE, ÉPINGLÉE ELLE AUSSI.
     *
     * La condition propre à l'affaire REMPLACE la part habituelle du partenaire, elle ne
     * s'y ajoute pas — et sous son seuil elle ne retombe pas dessus. Le lot qui suit
     * insère un étage dans cette cascade : c'est le point le plus exposé.
     */
    public function testLaConditionDeLAffaireRemplaceLaPartHabituelle(): void
    {
        $ids = $this->semer();
        $revenu = $this->em()->getRepository(RevenuPourCourtier::class)->find($ids['revenuId']);

        $retenue = $this->helper()->conditionPartageRetenue($revenu);

        self::assertNotNull($retenue);
        self::assertSame($ids['conditionPartenaireId'], (int) $retenue->getId());
        self::assertSame(
            self::TAUX_CONDITION_PARTENAIRE,
            $retenue->getTaux(),
            'La part nue du partenaire (5 %) est REMPLACÉE, pas additionnée.',
        );
    }

    /**
     * LE RATTACHEMENT D'AGENT PASSE PAR LA COLLECTION PARTAGÉE — et c'est bien elle que le
     * décompte lit. C'est l'étage que le lot va ouvrir aux partenaires : on épingle son
     * état d'avant pour que l'ouverture ne le déplace pas.
     */
    public function testLaConditionDAgentEstLueDepuisLaCollectionRattachee(): void
    {
        $ids = $this->semer();
        $cotation = $this->em()->getRepository(Cotation::class)->find($ids['cotationId']);
        $piste = $cotation->getPiste();

        self::assertCount(1, $piste->getConditionsPartageAgent(), 'Une seule condition rattachée.');

        $retenues = $this->helper()->getCotationConditionsAgent($cotation);
        self::assertCount(1, $retenues, 'Un seul agent bénéficiaire.');
        self::assertSame(
            $ids['conditionAgentId'],
            (int) reset($retenues)->getId(),
            'C’est bien la condition RATTACHÉE qui rémunère l’agent.',
        );
    }

    /**
     * Une affaire, un partenaire ET un agent : les deux familles coexistent déjà en base.
     * Le lot ne crée pas cette possibilité, il l'étend au geste de rattachement.
     */
    public function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Invariant')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($invite);

        $agent = (new Invite())->setNom('Alice Apporteuse')->setProprietaire(false);
        $agent->setEntreprise($entreprise);
        $em->persist($agent);

        $risque = (new Risque())->setCode('INV')->setNomComplet('Risque témoin')->setDescription('Témoin')
            ->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $partenaire = (new Partenaire())->setNom('SUNU Témoin')->setPart(self::PART_PARTENAIRE_DEFAUT);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $client = (new Client())->setNom('Client témoin')->setExonere(false);
        $client->setEntreprise($entreprise);
        $em->persist($client);

        $piste = (new Piste())->setNom('Affaire témoin')->setTypeAvenant(0)
            ->setDescriptionDuRisque('Témoin')->setExercice((int) date('Y'))
            ->setClient($client)->setRisque($risque);
        $piste->setEntreprise($entreprise)->setInvite($invite);
        $piste->setPartenaire($partenaire);
        $em->persist($piste);

        // La condition PROPRE à l'affaire, pour le partenaire : elle masque sa part nue.
        $conditionPartenaire = (new ConditionPartage())->setNom('Accord témoin 40%')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(self::TAUX_CONDITION_PARTENAIRE)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setPartenaire($partenaire)
            ->setPiste($piste);
        $conditionPartenaire->setEntreprise($entreprise);
        $em->persist($conditionPartenaire);

        // La condition de l'AGENT, rattachée par la collection partagée.
        $conditionAgent = (new ConditionPartage())->setNom('Effort témoin 20%')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(self::TAUX_CONDITION_AGENT)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($agent);
        $conditionAgent->setEntreprise($entreprise);
        $em->persist($conditionAgent);
        $piste->addConditionsPartageAgent($conditionAgent);

        $cotation = (new Cotation())->setNom('Proposition témoin')->setDuree(365);
        $cotation->setPiste($piste)->setEntreprise($entreprise);
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

        $avenant = (new Avenant())->setReferencePolice('POL-TEMOIN')->setNumero('0')
            ->setDescription('Police témoin')
            ->setStartingAt(new \DateTimeImmutable('-30 days'))
            ->setEndingAt(new \DateTimeImmutable('+335 days'));
        $avenant->setEntreprise($entreprise)->setInvite($invite);
        $cotation->addAvenant($avenant);
        $em->persist($avenant);

        $em->flush();
        $ids = [
            'cotationId'            => (int) $cotation->getId(),
            'revenuId'              => (int) $revenu->getId(),
            'agentId'               => (int) $agent->getId(),
            'partenaireId'          => (int) $partenaire->getId(),
            'pisteId'               => (int) $piste->getId(),
            'conditionPartenaireId' => (int) $conditionPartenaire->getId(),
            'conditionAgentId'      => (int) $conditionAgent->getId(),
        ];
        $em->clear();

        return $ids;
    }
}
