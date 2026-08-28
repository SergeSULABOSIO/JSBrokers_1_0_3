<?php

namespace App\Tests\Ai;

use App\Ai\Tool\EffortCommercialAgentTool;
use App\Controller\Admin\PartageRattachementController;
use App\Entity\Avenant;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Piste;
use App\Entity\Tranche;
use App\Service\Partage\RattachementDuPartage;
use App\Services\Canvas\Provider\Form\AvenantFormCanvasProvider;
use App\Services\Canvas\Provider\Form\CotationFormCanvasProvider;
use App\Services\Canvas\Provider\Form\PisteFormCanvasProvider;
use App\Services\Canvas\Provider\Form\TrancheFormCanvasProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PARITÉ UI ⊆ KET SUR LE RATTACHEMENT DES CONDITIONS DE PARTAGE.
 *
 * La consigne est sans exception : tout ce que l'écran permet, Ket doit le savoir et le
 * faire. Un test à cas choisis n'aurait tenu que les cas qu'on aurait pensé à choisir —
 * c'est ainsi que le partenaire est resté sans rattachement alors que la collection qui
 * l'aurait porté existait déjà, générique, sous un nom qui disait « agent ».
 *
 * Ce test ÉNUMÈRE donc depuis le CODE de l'écran — les actions déclarées par les quatre
 * canevas de formulaire, les entités que les routes acceptent, les refus que l'autorité
 * rend — et exige que chacun ait son pendant chez l'assistant. Ajouter un geste à l'écran
 * sans l'ouvrir à Ket casse ici.
 *
 * Il ne vérifie AUCUN montant : d'autres tests s'en chargent, et le témoin d'invariance
 * (`PartageInvariantTest`) épingle la répartition au centime. Ici, c'est la SURFACE qui est
 * en jeu : ce qui est atteignable de part et d'autre.
 */
class RattachementPariteKetTest extends KernelTestCase
{
    /** Les quatre écrans de l'arbre d'une affaire, et leur canevas. */
    private const CANEVAS = [
        'piste'    => [PisteFormCanvasProvider::class, Piste::class],
        'cotation' => [CotationFormCanvasProvider::class, Cotation::class],
        'avenant'  => [AvenantFormCanvasProvider::class, Avenant::class],
        'tranche'  => [TrancheFormCanvasProvider::class, Tranche::class],
    ];

    /**
     * Les actions de partage déclarées par un écran.
     *
     * @return array<int, array<string, mixed>>
     */
    private function actionsDePartage(string $entite): array
    {
        self::bootKernel();
        [$provider, $classe] = self::CANEVAS[$entite];

        $actions = static::getContainer()->get($provider)
            ->getCanvas(new $classe(), null)['parametres']['attribute_actions'] ?? [];

        return array_values(array_filter(
            $actions,
            static fn (array $a) => ($a['groupe'] ?? null) === 'Partage',
        ));
    }

    // ===================== 1. Les quatre écrans offrent les deux gestes =====================

    /**
     * LES QUATRE ÉCRANS DE L'ARBRE, ET LES DEUX GESTES SUR CHACUN.
     *
     * C'est l'énumération de départ : le reste s'appuie dessus. On travaille depuis la
     * liste des avenants ou des tranches, presque jamais depuis la piste — devoir remonter
     * l'arbre pour reconnaître un effort, c'est un geste qu'on finit par ne plus faire.
     */
    public function testLesQuatreEcransOffrentRattacherEtDetacher(): void
    {
        foreach (array_keys(self::CANEVAS) as $entite) {
            $actions = $this->actionsDePartage($entite);
            $libelles = array_column($actions, 'label');

            self::assertContains('Rattacher une condition de partage', $libelles, sprintf(
                'L’écran « %s » doit pouvoir rattacher.',
                $entite,
            ));
            self::assertContains('Détacher la condition de partage', $libelles, sprintf(
                'L’écran « %s » doit pouvoir détacher.',
                $entite,
            ));

            foreach ($actions as $action) {
                self::assertNotEmpty($action['url'] ?? null, 'Une action sans URL ne fait rien.');
                self::assertStringStartsWith('/admin/partage/' . $entite . '/', $action['url']);
            }
        }
    }

    /**
     * LES DEUX GESTES PASSENT PAR LE MÊME PICKER — un seul chemin, pas deux.
     *
     * Le détachement était un appel direct. Cela ne suffit plus depuis qu'une affaire peut
     * porter DEUX conditions : il faut dire laquelle. Brancher selon leur nombre aurait
     * donné deux comportements pour un même bouton.
     */
    public function testLeDetachementPasseParLeMemePicker(): void
    {
        foreach (array_keys(self::CANEVAS) as $entite) {
            $parLibelle = [];
            foreach ($this->actionsDePartage($entite) as $action) {
                $parLibelle[$action['label']] = $action;
            }

            $detacher = $parLibelle['Détacher la condition de partage'];
            self::assertSame('ui:partage.picker-request', $detacher['event'], sprintf(
                'Écran « %s » : le détachement doit ouvrir le picker.',
                $entite,
            ));
            self::assertStringContainsString('conditions-picker?mode=detacher', $detacher['url']);
        }
    }

    /**
     * LES DRAPEAUX DES ACTIONS SONT DEUX, ET DÉCLARÉS.
     *
     * Le voyant servait aussi de drapeau : sa présence ouvrait « Détacher », son absence
     * « Rattacher ». Cela ne tient plus — une affaire portant un apporteur reste
     * rattachable pour un agent. Et un drapeau non DÉCLARÉ sur l'entité ne figure pas dans
     * le `data-entity` : l'action resterait invisible, sans la moindre erreur.
     */
    public function testLesDeuxDrapeauxSontDeclaresSurLesQuatreEntites(): void
    {
        foreach (self::CANEVAS as $entite => [, $classe]) {
            foreach (['partageLibelle', 'partageRattachable', 'partageDetachable'] as $drapeau) {
                self::assertTrue(
                    property_exists($classe, $drapeau),
                    sprintf('%s doit DÉCLARER %s, sinon l’action est invisible en silence.', $classe, $drapeau),
                );

                $propriete = new \ReflectionProperty($classe, $drapeau);
                $groupes = $propriete->getAttributes(\Symfony\Component\Serializer\Annotation\Groups::class);
                self::assertNotEmpty($groupes, sprintf(
                    '%s::$%s doit porter #[Groups(["list:read"])] pour atteindre le data-entity.',
                    $classe,
                    $drapeau,
                ));
                self::assertContains('list:read', $groupes[0]->getArguments()[0]);
            }
        }

        // Et les actions s'y appuient : un drapeau déclaré mais jamais consulté ne sert à rien.
        $conditions = [];
        foreach (array_keys(self::CANEVAS) as $entite) {
            foreach ($this->actionsDePartage($entite) as $action) {
                $conditions[] = $action['condition']['field'] ?? null;
            }
        }
        self::assertContains('partageRattachable', $conditions);
        self::assertContains('partageDetachable', $conditions);
    }


    /**
     * LES DRAPEAUX ARRIVENT EN VRAIS BOOLÉENS DANS LE `data-entity`.
     *
     * `condition-action.js` compare en égalité SOUPLE (`valeur == condition.value`). Un
     * booléen sérialisé en CHAÎNE y devient un piège : en JavaScript, `'true' == true` vaut
     * FALSE — la chaîne passe par un nombre et devient NaN. L'action serait restée
     * invisible en barre d'outils comme au clic droit, sans la moindre erreur.
     *
     * Le voyant, lui, doit rester une CHAÎNE ou null : c'est un libellé.
     */
    public function testLesDrapeauxArriventEnBooleensDansLeDataEntity(): void
    {
        self::bootKernel();
        $serializer = static::getContainer()->get('serializer');

        $piste = new Piste();
        $piste->partageRattachable = true;
        $piste->partageDetachable = false;
        $piste->partageLibelle = 'Apporteur : SUNU Courtage';

        $charge = json_decode(
            $serializer->serialize($piste, 'json', ['groups' => ['list:read']]),
            true,
        );

        self::assertArrayHasKey('partageRattachable', $charge, 'Le drapeau doit atteindre le data-entity.');
        self::assertTrue($charge['partageRattachable'], 'Un VRAI booléen, pas la chaîne « true ».');
        self::assertFalse($charge['partageDetachable']);
        self::assertIsString($charge['partageLibelle']);
    }

    // ===================== 2. Ket couvre exactement les mêmes cibles =====================

    /**
     * LES ENTITÉS QUE L'ÉCRAN ACCEPTE SONT CELLES QUE KET ACCEPTE.
     *
     * Les deux listes existent en double — l'une dans le contrôleur (elle valide un
     * fragment d'URL), l'autre dans le schéma de l'outil. Rien ne force leur accord, et
     * leur divergence ne lèverait rien : Ket refuserait simplement une cible que l'écran
     * traite, ou proposerait une cible que la route ignore.
     */
    public function testKetAccepteExactementLesMemesCibles(): void
    {
        $carteControleur = (new \ReflectionClass(PartageRattachementController::class))->getConstant('ENTITES');
        $ecran = array_map(
            static fn (string $fqcn) => substr($fqcn, strrpos($fqcn, '\\') + 1),
            array_values($carteControleur),
        );

        $ket = (new \ReflectionClass(EffortCommercialAgentTool::class))->getConstant('CIBLES');

        sort($ecran);
        $ketTrie = $ket;
        sort($ketTrie);

        self::assertSame($ecran, $ketTrie, 'Les cibles de l’écran et celles de Ket doivent coïncider.');
    }

    /** Les deux gestes de l'écran sont les deux actions de l'outil, sans reste. */
    public function testLesDeuxGestesSontLesDeuxActionsDeLOutil(): void
    {
        self::bootKernel();
        $schema = static::getContainer()->get(EffortCommercialAgentTool::class)->schema();

        self::assertSame(['rattacher', 'detacher'], $schema['properties']['action']['enum']);
    }

    // ===================== 3. Aucune famille n'est fermée à Ket =====================

    /**
     * LA FAMILLE N'EST PAS UN PARAMÈTRE — ni à l'écran, ni chez Ket.
     *
     * L'utilisateur choisit une CONDITION, qui porte déjà sa famille. Ajouter un paramètre
     * de famille aurait ouvert la porte à la contradiction (« famille: agent » avec une
     * condition de partenaire), qu'il aurait alors fallu arbitrer.
     */
    public function testLaFamilleNEstPasUnParametre(): void
    {
        self::bootKernel();
        $schema = static::getContainer()->get(EffortCommercialAgentTool::class)->schema();

        self::assertArrayNotHasKey('famille', $schema['properties']);
        self::assertArrayNotHasKey('type', $schema['properties']);
        self::assertArrayHasKey('condition', $schema['properties']);
    }

    /**
     * LA DESCRIPTION N'INTERDIT PLUS LE PARTENAIRE — c'est ce que le modèle lit pour décider.
     *
     * Elle disait « elle doit désigner un AGENT interne : une condition de partenaire
     * externe n'a pas sa place ici ». Corriger le code sans corriger la phrase aurait laissé
     * l'outil inaccessible aux partenaires, avec un schéma qui les accepte.
     */
    public function testLaDescriptionOuvreLesDeuxFamilles(): void
    {
        self::bootKernel();
        $outil = static::getContainer()->get(EffortCommercialAgentTool::class);

        $texte = $outil->description() . ' ' . $outil->aiguillage()
            . ' ' . $outil->schema()['properties']['condition']['description'];

        self::assertStringContainsString('PARTENAIRE', $texte);
        self::assertStringContainsString('AGENT', $texte);
        self::assertStringNotContainsString('n\'a pas sa place ici', $texte);

        // Les deux règles propres au partenaire doivent être dites : sans elles, le modèle
        // annoncerait un rattachement sans mentionner la désignation qu'il entraîne.
        self::assertStringContainsString('DÉSIGNE', $texte, 'La désignation d’apporteur doit être annoncée.');
        self::assertStringContainsString('PAR FAMILLE', $texte, 'Un bénéficiaire par camp, pas un en tout.');
    }

    // ===================== 4. Les refus sont ceux de l'autorité =====================

    /**
     * TOUS LES REFUS DE L'AUTORITÉ SONT ATTEIGNABLES PAR KET.
     *
     * L'écran et l'assistant consultent la même autorité — c'est le contrat. Ce test le
     * vérifie sur le CODE : chaque méthode de refus doit être appelée par l'outil, sans
     * quoi Ket deviendrait le contournement de la règle qu'elle porte.
     */
    public function testLOutilConsulteTousLesRefusDeLAutorite(): void
    {
        $outil = (string) file_get_contents(
            __DIR__ . '/../../src/Ai/Tool/EffortCommercialAgentTool.php',
        );

        $refus = array_values(array_filter(
            get_class_methods(RattachementDuPartage::class),
            static fn (string $m) => str_starts_with($m, 'refus'),
        ));
        self::assertNotEmpty($refus, 'L’autorité doit porter des refus.');

        $autorite = (string) file_get_contents(
            __DIR__ . '/../../src/Service/Partage/RattachementDuPartage.php',
        );

        foreach ($refus as $methode) {
            // Consulté par l'OUTIL, ou par un autre refus de l'autorité — la délégation
            // est ce qu'on veut : `refusDuLot()` applique `refusDeRattachement()` à
            // chaque affaire, et exiger un appel direct punirait ce factorisage.
            $parLOutil = str_contains($outil, $methode . '(');
            $parUnAutreRefus = substr_count($autorite, $methode . '(') > 1;
            self::assertTrue(
                $parLOutil || $parUnAutreRefus,
                sprintf(
                    'Ket doit atteindre %s(), directement ou par délégation : une règle qui ne '
                    . 'vaut que pour l’écran n’est pas une règle.',
                    $methode,
                ),
            );
        }

        // Et les DEUX gestes consultent bien l'autorité, chacun le sien.
        self::assertStringContainsString('refusDuLot(', $outil, 'Le rattachement en lot.');
        self::assertStringContainsString('refusDeDetachement(', $outil, 'Le détachement.');

        // Et la désignation d'intermédiaire, qui n'est pas un refus mais une écriture : Ket
        // doit la produire aussi, sinon son plan écrirait une règle sans effet.
        self::assertStringContainsString('partenaire', $outil);
    }

    /**
     * LE CHEMIN GÉNÉRIQUE RESTE FERMÉ POUR LES DEUX FAMILLES.
     *
     * Rien n'empêcherait le modèle d'écrire directement sur `Piste.conditionsPartageAgent`
     * par `preparer_operations`. Le moteur de mutation refuse — mais il ne gouvernait
     * qu'une famille : l'autre serait devenue le contournement de toutes les règles.
     */
    public function testLeMoteurDeMutationGouverneLesDeuxFamilles(): void
    {
        $moteur = (string) file_get_contents(
            __DIR__ . '/../../src/Service/Workspace/WorkspaceMutationService.php',
        );

        self::assertStringContainsString('conditionsPartageAgent', $moteur);
        // Il lit les conditions PAR FAMILLE (`conditions()`), et non une seule.
        self::assertStringContainsString('rattachement->conditions(', $moteur);
        self::assertStringContainsString('familleDe(', $moteur);
    }

    /**
     * LE PICKER PROPOSE LES DEUX FAMILLES.
     *
     * C'était le dernier verrou de l'asymétrie, et il était invisible : le champ de
     * formulaire filtrait `agent IS NOT NULL`, ce qui fermait le geste à TOUS les chemins
     * d'un coup — écran, picker et assistant, puisque l'écriture d'un ManyToMany passe
     * toujours par le FormType.
     */
    public function testLeChampDeRattachementNeFiltrePlusLaFamille(): void
    {
        $champ = (string) file_get_contents(
            __DIR__ . '/../../src/Form/ConditionPartageAgentAutocompleteField.php',
        );

        self::assertStringNotContainsString(
            "'.agent IS NOT NULL'",
            $champ,
            'Ce filtre fermait le rattachement aux partenaires, sur tous les chemins à la fois.',
        );
    }
}
