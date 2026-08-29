<?php

namespace App\Tests\Ai;

use App\Ai\Tool\OuvrirRubriqueTool;
use App\Services\Canvas\Provider\Form\InviteFormCanvasProvider;
use App\Services\Canvas\Provider\Form\PartenaireFormCanvasProvider;
use App\Services\Canvas\Provider\List\ProductionIntermediaireListCanvasProvider;
use App\Services\Search\CotationSouscriptionScope;
use App\Services\Search\ProductionScope;
use App\Entity\Invite;
use App\Entity\Partenaire;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PARITÉ UI ⊆ KET SUR LA RUBRIQUE « PRODUCTION INTERMÉDIAIRES ».
 *
 * La consigne est sans exception : tout ce que l'écran permet, Ket doit le savoir et le
 * faire. Un test à cas choisis n'aurait tenu que les cas qu'on aurait pensé à choisir —
 * c'est ainsi que le partenaire était resté sans rapport de production alors que le socle
 * savait le rendre. Ce test ÉNUMÈRE donc depuis le CODE de l'écran (chips de la rubrique,
 * actions de fiche) et exige que chaque geste ait son pendant côté assistant.
 *
 * ── LE PIÈGE PROPRE À CETTE RUBRIQUE ────────────────────────────────────────────────
 * Elle n'a pas d'entité Doctrine : ses lignes sont CALCULÉES par le moteur de partage.
 * `EntiteLexique` l'exclut donc de son énumération (garde `class_exists`), et sans une
 * exception explicite Ket ne pourrait pas ouvrir une rubrique que l'écran propose — une
 * parité rompue sans le moindre message.
 */
class ProductionIntermediairePariteKetTest extends KernelTestCase
{
    /**
     * LA RUBRIQUE EST OUVRABLE PAR KET.
     *
     * C'est le premier verrou, et le plus facile à perdre : ajouter une rubrique au menu
     * ne l'ajoute pas au vocabulaire de l'assistant quand elle n'a pas d'entité.
     */
    public function testLaRubriqueEstDansLEnumerationDeKet(): void
    {
        self::bootKernel();
        $schema = static::getContainer()->get(OuvrirRubriqueTool::class)->schema();

        self::assertContains(
            ProductionScope::ENTITE,
            $schema['properties']['entite']['enum'],
            'Ket doit pouvoir ouvrir « Production intermédiaires ».',
        );
    }

    /**
     * LES VALEURS DES CHIPS SONT CELLES DE L'ASSISTANT, sans exception.
     *
     * Deux listes de valeurs finissent toujours par désigner deux sous-ensembles. Elles
     * viennent donc de la même constante — et ce test le constate plutôt que de l'espérer.
     */
    public function testLesValeursDesChipsSontCellesDeLAssistant(): void
    {
        self::bootKernel();
        $schema = static::getContainer()->get(OuvrirRubriqueTool::class)->schema()['properties'];

        // Le STATUT passe par « validation » — le même mot que pour les propositions, et
        // la même partition : inventer un second argument aurait fait deux vocabulaires
        // pour un seul ensemble.
        self::assertSame(
            array_keys(ProductionScope::GROUPES[ProductionScope::CLE_STATUT]),
            $schema['validation']['enum'],
            'Le statut de la production diverge entre l’écran et Ket.',
        );
        self::assertSame(
            array_keys(CotationSouscriptionScope::VALEURS),
            array_keys(ProductionScope::GROUPES[ProductionScope::CLE_STATUT]),
            'La partition des affaires n’a qu’une source.',
        );

        // Le TYPE est partagé avec les rétros : les deux rubriques nomment les mêmes
        // familles, et le socle `BeneficiaireCritere` est là pour qu'elles ne divergent pas.
        self::assertSame(
            array_keys(ProductionScope::GROUPES[ProductionScope::CLE_TYPE]),
            $schema['type']['enum'],
            'Le type d’intermédiaire diverge entre l’écran et Ket.',
        );
    }

    /**
     * LES TROIS CHIPS DE L'ÉCRAN ONT CHACUN LEUR PARAMÈTRE.
     *
     * On lit les chips DANS le canevas — donc depuis le code de l'écran — et l'on exige
     * que l'assistant sache poser chacun. Ajouter un chip sans l'ouvrir à Ket casse ce test.
     */
    public function testChaqueChipDeLEcranALeSienChezKet(): void
    {
        self::bootKernel();
        $canvas = static::getContainer()->get(ProductionIntermediaireListCanvasProvider::class)->getCanvas();
        $schema = static::getContainer()->get(OuvrirRubriqueTool::class)->schema()['properties'];

        $parametreParChip = [
            ProductionScope::CLE_STATUT => 'validation',
            ProductionScope::CLE_TYPE => 'type',
            ProductionScope::CLE_BENEFICIAIRE => 'beneficiaire',
        ];

        foreach ($canvas['filtres_predefinis'] as $groupe) {
            self::assertArrayHasKey(
                $groupe['critere'],
                $parametreParChip,
                sprintf('Le chip « %s » n’a aucun paramètre chez Ket.', $groupe['critere']),
            );
            self::assertArrayHasKey(
                $parametreParChip[$groupe['critere']],
                $schema,
                sprintf('Le paramètre de « %s » manque au schéma.', $groupe['critere']),
            );
        }
    }

    /**
     * LE CHIP-SÉLECTEUR PROPOSE DEUX FAMILLES ; `beneficiaire` DOIT LES RÉSOUDRE TOUTES DEUX.
     *
     * L'écran laisse choisir un agent OU un partenaire. Ne chercher que parmi les agents
     * rendrait « ouvre la production de SUNU Courtage » introuvable sur une rubrique qui la
     * contient précisément. On lit les entités des sélecteurs DANS le canevas, et l'on
     * exige que la résolution de l'outil les couvre — vérifiée sur le code, l'outil n'ayant
     * pas de base ici.
     */
    public function testLeSelecteurDeBeneficiaireEtKetResolventLesMemesFamilles(): void
    {
        self::bootKernel();
        $canvas = static::getContainer()->get(ProductionIntermediaireListCanvasProvider::class)->getCanvas();

        $familles = [];
        foreach ($canvas['filtres_predefinis'] as $groupe) {
            if ($groupe['critere'] !== ProductionScope::CLE_BENEFICIAIRE) {
                continue;
            }
            foreach ($groupe['options'] as $option) {
                if (isset($option['selecteur']['entite'])) {
                    $familles[] = $option['selecteur']['entite'];
                }
            }
        }

        sort($familles);
        self::assertSame(['Invite', 'Partenaire'], $familles, 'L’écran propose les deux familles.');

        // Et l'outil sait chercher dans les deux : les deux dépôts sont dans sa signature.
        $source = (string) file_get_contents(
            __DIR__ . '/../../src/Ai/Tool/OuvrirRubriqueTool.php',
        );
        self::assertStringContainsString('InviteRepository', $source);
        self::assertStringContainsString('PartenaireRepository', $source);
        // Et le bloc du bénéficiaire sert les DEUX rubriques, pas seulement les rétros.
        self::assertStringContainsString('ProductionScope::ENTITE => ProductionScope::class', $source);
    }

    /**
     * LES DEUX FICHES OUVRENT LA PRODUCTION — et par le MÊME événement.
     *
     * C'est le geste que l'utilisateur fait le plus souvent : « montre-moi ce que celui-là
     * apporte ». Qu'une famille l'ait et pas l'autre est précisément l'asymétrie que
     * l'unification des rétros a dû corriger.
     */
    public function testLesDeuxFichesOuvrentLaProduction(): void
    {
        self::bootKernel();

        foreach ([
            'agent' => [InviteFormCanvasProvider::class, new Invite()],
            'partenaire' => [PartenaireFormCanvasProvider::class, new Partenaire()],
        ] as $famille => [$providerClass, $objet]) {
            $canvas = static::getContainer()->get($providerClass)->getCanvas($objet, null);
            $actions = $canvas['parametres']['attribute_actions'] ?? [];

            self::assertContains(
                'ui:production.rubrique-request',
                array_column($actions, 'event'),
                sprintf('La fiche d’un %s doit ouvrir sa production.', $famille),
            );
        }
    }

    /**
     * L'ANCIEN VOCABULAIRE RESTE COMPRIS.
     *
     * L'écran s'appelait « rapport de production » et disparaît ; le mot, lui, reste celui
     * des courtiers. Un terme retiré du lexique devient une demande refusée.
     */
    public function testLAncienNomOuvreEncoreLaRubrique(): void
    {
        self::bootKernel();
        $alias = static::getContainer()->get(\App\Service\Workspace\WorkspaceAccessResolver::class)
            ->aliasEntites();

        self::assertArrayHasKey(ProductionScope::ENTITE, $alias);
        self::assertContains('Rapport de production', $alias[ProductionScope::ENTITE]);
    }
}
