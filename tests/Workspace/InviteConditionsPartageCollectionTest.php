<?php

namespace App\Tests\Workspace;

use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * LA FICHE D'UN INVITÉ PORTE SES CONDITIONS DE PARTAGE — comme celle d'un partenaire.
 *
 * C'est là que se décrit ce que le cabinet lui rétrocède : taux, seuil, risques visés. La
 * condition ainsi créée ne s'applique encore à rien ; elle prend effet quand on la
 * rattache à une piste (champ « Agents internes rémunérés sur cette affaire »), et la même
 * règle sert alors autant d'affaires que l'agent en apporte.
 *
 * Ce que ce test verrouille :
 *  - la collection existe et pointe la bonne entité ;
 *  - le widget est réellement rendu dans le layout, avec `agent` comme champ parent — sans
 *    quoi une condition créée depuis cette fiche n'aurait aucun bénéficiaire ;
 *  - le champ `agent` figure dans le layout du dialogue de la condition, et non relégué
 *    en bas du formulaire par render_rest ;
 *  - la collection « Documents », déclarée mais jamais rendue faute d'une variable mal
 *    nommée, l'est enfin.
 */
class InviteConditionsPartageCollectionTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-invcp-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit InviteCP SARL';

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
        foreach (['condition_partage', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
    }

    public function testLeFormulaireDeLInvitePorteLaCollectionDeConditions(): void
    {
        $form = static::getContainer()->get(FormFactoryInterface::class)
            ->create(\App\Form\InviteType::class, new Invite());

        self::assertTrue(
            $form->has('conditionsPartageAgent'),
            'La fiche Invité doit porter ses conditions de partage, comme celle du Partenaire.',
        );

        $entry = $form->get('conditionsPartageAgent')->getConfig()->getOption('entry_type');
        self::assertSame(\App\Form\ConditionPartageType::class, $entry);
    }

    public function testLeWidgetEstRenduAvecAgentCommeChampParent(): void
    {
        $ids = $this->semer();
        $invite = $this->em()->getRepository(Invite::class)->find($ids['inviteId']);

        $canvas = static::getContainer()->get(CanvasBuilder::class)->getEntityFormCanvas($invite, $ids['entrepriseId']);
        $champs = $this->champsDuLayout($canvas['form_layout']);

        self::assertContains(
            'conditionsPartageAgent',
            $champs,
            'Le widget doit figurer dans le layout, sinon la collection reste invisible.',
        );
        // Le bug corrigé au passage : le widget « Documents » était ajouté à une variable
        // qui n'était jamais transmise à addCollectionWidgetsToLayout().
        self::assertContains('documents', $champs, 'La collection Documents doit être rendue elle aussi.');

        // `agent` est le ManyToOne par lequel la condition désigne son bénéficiaire :
        // c'est lui que le trait CRUD injecte à la création depuis cette fiche.
        $config = $this->configDeCollection($canvas['form_layout'], 'conditionsPartageAgent');
        self::assertSame('agent', $config['parentFieldName'] ?? null);
    }

    public function testLeDialogueDeLaConditionMontreSonBeneficiaireInterne(): void
    {
        $ids = $this->semer();
        $condition = new ConditionPartage();

        $canvas = static::getContainer()->get(CanvasBuilder::class)->getEntityFormCanvas($condition, $ids['entrepriseId']);
        $champs = $this->champsDuLayout($canvas['form_layout']);

        self::assertContains(
            'agent',
            $champs,
            'Le bénéficiaire interne doit être dans le layout, pas relégué en bas par render_rest.',
        );
        self::assertStringContainsString('AGENT INTERNE', $canvas['parametres']['form_intro']['description']);
    }

    public function testUneConditionCreeeDepuisLaFicheEstBienRattacheeALAgent(): void
    {
        $ids = $this->semer();
        $em = $this->em();
        $invite = $em->getRepository(Invite::class)->find($ids['inviteId']);

        $condition = (new ConditionPartage())->setNom('Prime apporteur')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(12.0)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($invite);
        $condition->setEntreprise($invite->getEntreprise());
        $em->persist($condition);
        $em->flush();
        $em->clear();

        $invite = $em->getRepository(Invite::class)->find($ids['inviteId']);
        self::assertCount(1, $invite->getConditionsPartageAgent());
        self::assertSame('Prime apporteur', $invite->getConditionsPartageAgent()->first()->getNom());

        // Elle décrit la règle mais ne s'applique encore à AUCUNE affaire : c'est le
        // rattachement à une piste qui lui donne effet.
        self::assertCount(0, $invite->getConditionsPartageAgent()->first()->getPistesAffectees());
    }

    public function testLaRubriqueNOffreAucuneCreation(): void
    {
        $ids = $this->semer();

        $canvas = static::getContainer()->get(CanvasBuilder::class)
            ->getEntityFormCanvas(new ConditionPartage(), $ids['entrepriseId']);

        // Une condition n'existe que RATTACHÉE : à un partenaire, à un agent, ou à une
        // piste. La créer depuis la liste produirait une règle orpheline. Le drapeau
        // GRISE « Ajouter » dans la barre d'outils et le menu contextuel — il ne le masque
        // pas : un bouton absent laisserait croire à un droit manquant, là où un bouton
        // inactif avec son infobulle dit où créer.
        self::assertTrue(
            $canvas['parametres']['creation_interdite'] ?? false,
            "La rubrique Conditions de partage ne doit pas proposer d'ajout.",
        );

        // … mais elle reste ÉDITABLE et SUPPRIMABLE : le drapeau ne touche que la création.
        self::assertNotEmpty($canvas['parametres']['endpoint_submit_url'] ?? null);
        self::assertNotEmpty($canvas['parametres']['endpoint_delete_url'] ?? null);
    }

    public function testLesFichesParentesRestentLesSeulsPointsDeCreation(): void
    {
        $ids = $this->semer();
        $builder = static::getContainer()->get(CanvasBuilder::class);
        $em = $this->em();

        // Les trois portes légitimes portent bien la collection, chacune avec le
        // rattachement qui va avec.
        $invite = $em->getRepository(Invite::class)->find($ids['inviteId']);
        self::assertContains(
            'conditionsPartageAgent',
            $this->champsDuLayout($builder->getEntityFormCanvas($invite, $ids['entrepriseId'])['form_layout']),
        );

        $partenaire = new \App\Entity\Partenaire();
        self::assertContains(
            'conditionPartages',
            $this->champsDuLayout($builder->getEntityFormCanvas($partenaire, $ids['entrepriseId'])['form_layout']),
        );

        $piste = new \App\Entity\Piste();
        $champsPiste = $this->champsDuLayout($builder->getEntityFormCanvas($piste, $ids['entrepriseId'])['form_layout']);
        self::assertContains('conditionsPartageExceptionnelles', $champsPiste);
        self::assertContains('conditionsPartageAgent', $champsPiste, "Le rattachement d'une condition d'agent à l'affaire.");
    }

    public function testLeCritereSurLeRisqueNOffreQueSesTroisCasReels(): void
    {
        $form = static::getContainer()->get(FormFactoryInterface::class)
            ->create(\App\Form\ConditionPartageType::class, new ConditionPartage());

        $config = $form->get('critereRisque')->getConfig();

        // Trois cas, et trois seulement : cibler, exclure, ou ne rien cibler. Le radio
        // « None » qu'ajoutait `required: false` ne veut rien dire — et la colonne,
        // NOT NULL, le refuse de toute façon.
        self::assertCount(3, $config->getOption('choices'));
        self::assertTrue($config->getOption('required'));
        // Le champ est EXPANDED : ses enfants sont les radios réellement rendus. Compter
        // ceux-là, et non l'option `placeholder` — que Symfony normalise à null dès que
        // le champ est requis —, c'est vérifier ce que l'utilisateur voit.
        self::assertCount(3, $form->get('critereRisque'), "Trois radios à l'écran, pas quatre.");

        // Le défaut est porté par l'entité : « aucun risque ciblé », le cas courant.
        self::assertSame(
            ConditionPartage::CRITERE_PAS_RISQUES_CIBLES,
            (new ConditionPartage())->getCritereRisque(),
        );
    }

    public function testLesDefautsDeCreationSontPosesPourUnAgent(): void
    {
        $ids = $this->semer();
        $invite = $this->em()->getRepository(Invite::class)->find($ids['inviteId']);

        // Le trait CRUD injecte le bénéficiaire AVANT de construire le formulaire : les
        // défauts peuvent donc s'appuyer dessus.
        $condition = (new ConditionPartage())->setAgent($invite);
        $form = static::getContainer()->get(FormFactoryInterface::class)
            ->create(\App\Form\ConditionPartageType::class, $condition);

        self::assertStringContainsString('Alice', (string) $form->get('nom')->getData());
        self::assertSame(5.0, $form->get('taux')->getData(), 'Taux par défaut en POINTS.');
        self::assertSame(0.0, $form->get('seuil')->getData(), 'Seuil nul = tout montant produit.');
    }

    public function testLeChampAgentDisparaitPourUneConditionDePartenaire(): void
    {
        $partenaire = (new \App\Entity\Partenaire())->setNom('MARSH SA')->setPart(10.0);
        $condition = (new ConditionPartage())->setPartenaire($partenaire);

        $form = static::getContainer()->get(FormFactoryInterface::class)
            ->create(\App\Form\ConditionPartageType::class, $condition);

        self::assertFalse(
            $form->has('agent'),
            "« Agent bénéficiaire » ne concerne pas une condition de partenaire externe.",
        );

        // ... et il reste proposé quand la question se pose.
        $libre = static::getContainer()->get(FormFactoryInterface::class)
            ->create(\App\Form\ConditionPartageType::class, new ConditionPartage());
        self::assertTrue($libre->has('agent'));
    }

    /** @return string[] noms de champs présents dans le layout, à plat */
    private function champsDuLayout(array $layout): array
    {
        $champs = [];
        foreach ($layout as $ligne) {
            foreach ($ligne['colonnes'] ?? [] as $colonne) {
                foreach ($colonne['champs'] ?? [] as $champ) {
                    $champs[] = is_array($champ) ? ($champ['field_code'] ?? '') : $champ;
                }
            }
        }

        return $champs;
    }

    /** La configuration d'un champ de collection dans le layout. */
    private function configDeCollection(array $layout, string $fieldName): array
    {
        foreach ($layout as $ligne) {
            foreach ($ligne['colonnes'] ?? [] as $colonne) {
                foreach ($colonne['champs'] ?? [] as $champ) {
                    if (is_array($champ) && ($champ['field_code'] ?? null) === $fieldName) {
                        return $champ['options'] ?? $champ;
                    }
                }
            }
        }

        return [];
    }

    /** @return array{inviteId:int, entrepriseId:int} */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('InviteCP Owner')->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($owner);
        $em->persist($entreprise);

        $invite = (new Invite())->setNom('Alice')->setProprietaire(false);
        $invite->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($invite);

        $em->flush();
        $ids = ['inviteId' => (int) $invite->getId(), 'entrepriseId' => (int) $entreprise->getId()];
        $em->clear();

        return $ids;
    }
}
