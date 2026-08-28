<?php

namespace App\Tests\Services;

use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Utilisateur;
use App\Form\ConditionPartageType;
use App\Services\Canvas\Provider\Form\ConditionPartageFormCanvasProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * CRÉER UNE CONDITION : LE MÊME GESTE POUR LES DEUX FAMILLES.
 *
 * `ConditionPartageType` ne déclarait AUCUN champ `partenaire`. Depuis la rubrique
 * « Conditions de partage », on ne pouvait donc créer que des conditions d'AGENT — un
 * intermédiaire n'y était pas désignable, et il fallait passer par sa fiche. L'agent, lui,
 * se choisissait librement d'un autocomplete. C'était toute l'asymétrie de la création, et
 * elle ne levait rien : un champ manquant ne se remarque pas, on croit simplement que « ça
 * ne se fait pas comme ça ».
 *
 * TROIS SITUATIONS, et le formulaire doit dire exactement ce qu'il sait faire :
 *
 *   1. en création AUTONOME — les deux familles, librement ;
 *   2. depuis la fiche d'un bénéficiaire — il est injecté, aucune question à poser ;
 *   3. depuis une AFFAIRE — le choix est « l'intermédiaire de cette affaire » ou un agent.
 *
 * Le canevas doit annoncer EXACTEMENT ce que le formulaire déclare : lui promettre un champ
 * qu'il ne rendra pas laisse une carte vide à l'écran, sans erreur.
 */
class ConditionPartageCreationTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-condition-creation@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Condition Creation SARL';

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
        foreach (['condition_partage', 'piste', 'partenaire', 'invite'] as $table) {
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
     * Le décor minimal : les champs d'autocomplétion exigent des entités GÉRÉES, et le
     * formulaire refuse tout net une entité qui n'est pas passée par l'EntityManager.
     *
     * @return array{entreprise: Entreprise, agent: Invite, partenaire: Partenaire}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Création')
            ->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')
            ->setAdresse('1 rue')->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')
            ->setUtilisateur($owner);
        $em->persist($entreprise);

        $agent = (new Invite())->setNom('Alice Apporteuse')->setProprietaire(false);
        $agent->setEntreprise($entreprise);
        $em->persist($agent);

        $partenaire = (new Partenaire())->setNom('SUNU Courtage')->setPart(20.0);
        $partenaire->setEntreprise($entreprise);
        $em->persist($partenaire);

        $em->flush();

        return ['entreprise' => $entreprise, 'agent' => $agent, 'partenaire' => $partenaire];
    }

    private function formulaire(ConditionPartage $condition): FormInterface
    {
        /** @var FormFactoryInterface $usine */
        $usine = static::getContainer()->get(FormFactoryInterface::class);

        return $usine->create(ConditionPartageType::class, $condition);
    }

    /** @return array<int, string> les champs du layout, à plat */
    private function champsDuCanevas(ConditionPartage $condition): array
    {
        $canvas = static::getContainer()->get(ConditionPartageFormCanvasProvider::class)
            ->getCanvas($condition, null);

        $champs = [];
        foreach ($canvas['form_layout'] as $rangee) {
            foreach ($rangee['colonnes'] ?? [] as $colonne) {
                foreach ($colonne['champs'] ?? [] as $champ) {
                    $champs[] = is_array($champ) ? ($champ['field_code'] ?? '') : $champ;
                }
            }
        }

        return $champs;
    }

    // ===================== 1. La création autonome =====================

    /**
     * DEPUIS LA RUBRIQUE, LES DEUX FAMILLES SE CHOISISSENT.
     *
     * C'est le geste qui n'existait pas. Une condition d'intermédiaire ne pouvait naître que
     * sur sa fiche — donc jamais au moment où l'on écrit la règle, mais seulement au moment
     * où l'on ouvre le partenaire.
     */
    public function testEnCreationAutonomeLesDeuxFamillesSontProposees(): void
    {
        $this->semer();
        $formulaire = $this->formulaire(new ConditionPartage());

        self::assertTrue($formulaire->has('agent'), 'L’agent restait choisissable : il le reste.');
        self::assertTrue($formulaire->has('partenaire'), 'L’intermédiaire doit l’être aussi.');
        self::assertTrue($formulaire->has('beneficiaireType'), 'Et le choix entre les deux se pose.');

        self::assertSame(
            [
                'Un agent interne' => ConditionPartageType::BENEFICIAIRE_AGENT,
                'Un intermédiaire externe' => ConditionPartageType::BENEFICIAIRE_INTERMEDIAIRE,
            ],
            $formulaire->get('beneficiaireType')->getConfig()->getOption('choices'),
        );
    }

    /** Le canevas annonce les trois champs, et conditionne chaque sélecteur à son choix. */
    public function testLeCanevasAutonomeDeclareLesDeuxSelecteurs(): void
    {
        $this->semer();
        $champs = $this->champsDuCanevas(new ConditionPartage());

        self::assertContains('beneficiaireType', $champs);
        self::assertContains('agent', $champs);
        self::assertContains('partenaire', $champs);
    }

    /**
     * L'ÉCRAN DIT LA PARTICULARITÉ QUI SUBSISTE.
     *
     * Le geste est le même, le SENS non : une condition d'intermédiaire s'applique à toutes
     * ses affaires dès l'enregistrement, là où celle d'un agent reste inerte jusqu'au
     * rattachement. C'est la seule différence qui demeure, et elle doit se LIRE — sinon elle
     * se découvre sur un montant.
     */
    public function testLaPorteeDeLaConditionDeIntermediaireEstAnnoncee(): void
    {
        $this->semer();

        $aide = $this->formulaire(new ConditionPartage())->get('partenaire')->getConfig()->getOption('help');
        self::assertStringContainsString('TOUTES les affaires', $aide);
        self::assertStringContainsString('rattachée', $aide, 'Et le contraste avec l’agent est dit.');
    }

    /** Une condition EXISTANTE reste corrigeable : le choix s'ouvre aussi pour elle. */
    public function testUneConditionExistanteResteCorrigeable(): void
    {
        $s = $this->semer();

        $condition = (new ConditionPartage())->setNom('À corriger')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)->setTaux(10.0)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($s['agent']);
        $condition->setEntreprise($s['entreprise']);
        $this->em()->persist($condition);
        $this->em()->flush();

        $formulaire = $this->formulaire($condition);

        self::assertTrue(
            $formulaire->has('beneficiaireType'),
            'Une erreur de famille doit pouvoir se corriger, pas seulement se subir.',
        );
        self::assertSame(
            ConditionPartageType::BENEFICIAIRE_AGENT,
            $formulaire->get('beneficiaireType')->getConfig()->getOption('data'),
            'Et le choix s’ouvre sur la famille actuelle, jamais sur l’autre.',
        );
    }

    // ===================== 2. Depuis la fiche d'un bénéficiaire =====================

    /**
     * LE BÉNÉFICIAIRE INJECTÉ NE SE REDEMANDE PAS.
     *
     * Une condition créée depuis la fiche d'un agent le reçoit du parent
     * (`parentFieldName`). Lui reposer la question, ce serait faire chercher une réponse que
     * l'écran possède déjà — et permettre de la contredire.
     */
    public function testDepuisLaFicheDunAgentLeChoixNeSePosePas(): void
    {
        $s = $this->semer();

        $condition = new ConditionPartage();
        $condition->setAgent($s['agent']);

        $formulaire = $this->formulaire($condition);

        self::assertTrue($formulaire->has('agent'), 'Le bénéficiaire reste visible…');
        self::assertFalse($formulaire->has('beneficiaireType'), '…mais il ne se discute pas.');
        self::assertFalse($formulaire->has('partenaire'));
    }

    /** Et symétriquement depuis la fiche d'un intermédiaire — écran inchangé. */
    public function testDepuisLaFicheDunPartenaireLeChoixNeSePosePas(): void
    {
        $s = $this->semer();

        $condition = new ConditionPartage();
        $condition->setPartenaire($s['partenaire']);

        $formulaire = $this->formulaire($condition);

        self::assertFalse($formulaire->has('agent'), 'L’agent n’a rien à faire ici.');
        self::assertFalse($formulaire->has('beneficiaireType'));

        $champs = $this->champsDuCanevas($condition);
        self::assertNotContains('agent', $champs);
        self::assertNotContains('beneficiaireType', $champs);
    }

    // ===================== 3. Depuis une affaire =====================

    /**
     * DEPUIS UNE AFFAIRE, LE PARTENAIRE N'EST PAS LIBREMENT DÉSIGNABLE — et c'est voulu.
     *
     * `premiereConditionApplicable()` ne vérifie pas l'identité du partenaire sur une
     * condition PROPRE à l'affaire : nommer un tiers paierait l'intermédiaire du jour à son
     * taux. Ouvrir ce choix par symétrie élargirait un trou au lieu de le combler.
     */
    public function testDepuisUneAffaireLeChoixResteLIntermediaireDeLAffaire(): void
    {
        $s = $this->semer();

        $piste = (new Piste())->setNom('Affaire Kibali')->setTypeAvenant(0)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'));
        $piste->setEntreprise($s['entreprise']);
        $piste->setPartenaire($s['partenaire']);
        $this->em()->persist($piste);
        $this->em()->flush();

        $condition = new ConditionPartage();
        $condition->setPiste($piste);

        $formulaire = $this->formulaire($condition);

        self::assertTrue($formulaire->has('beneficiaireType'), 'La question se pose bien.');
        self::assertFalse(
            $formulaire->has('partenaire'),
            'Mais l’intermédiaire ne se choisit pas librement ici : c’est celui de l’affaire.',
        );

        self::assertArrayHasKey(
            "L'intermédiaire de cette affaire (SUNU Courtage)",
            $formulaire->get('beneficiaireType')->getConfig()->getOption('choices'),
        );

        // Et le canevas ne promet pas un champ que le formulaire ne rendra pas : une carte
        // vide à l'écran ne lève rien et ne s'explique pas.
        self::assertNotContains('partenaire', $this->champsDuCanevas($condition));
    }

    /** Sans intermédiaire sur l'affaire, l'option n'est pas proposée plutôt qu'offerte en vain. */
    public function testSansIntermediaireSurLAffaireLOptionNestPasProposee(): void
    {
        $s = $this->semer();

        $piste = (new Piste())->setNom('Affaire sans apporteur')->setTypeAvenant(0)
            ->setDescriptionDuRisque('x')->setExercice((int) date('Y'));
        $piste->setEntreprise($s['entreprise']);
        $this->em()->persist($piste);
        $this->em()->flush();

        $condition = new ConditionPartage();
        $condition->setPiste($piste);

        self::assertSame(
            ['Un agent interne' => ConditionPartageType::BENEFICIAIRE_AGENT],
            $this->formulaire($condition)->get('beneficiaireType')->getConfig()->getOption('choices'),
        );
    }
}
