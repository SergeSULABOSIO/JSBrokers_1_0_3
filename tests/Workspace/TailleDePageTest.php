<?php

namespace App\Tests\Workspace;

use App\Service\Liste\TailleDePage;
use PHPUnit\Framework\TestCase;

/**
 * COMBIEN DE FICHES PAR PAGE (Serge, 2026-10-29) : l'utilisateur choisit dans la barre du bas
 * (10, 20, 50, 100 ; 20 par défaut), le SERVEUR borne — chaque fiche chargée est décomptée en
 * tokens. Ce que le navigateur envoie hors de la liste vaut le défaut.
 */
class TailleDePageTest extends TestCase
{
    public function testLesChoixSontBornesParLeServeur(): void
    {
        self::assertSame([10, 20, 50, 100], TailleDePage::CHOIX);
        self::assertSame(20, TailleDePage::PAR_DEFAUT);
        self::assertContains(TailleDePage::PAR_DEFAUT, TailleDePage::CHOIX);

        self::assertSame(50, TailleDePage::depuis(50));
        self::assertSame(100, TailleDePage::depuis('100'));
        foreach ([null, '', 0, -10, 25, 500, 100000, 'abc', [], 20.5] as $hors) {
            self::assertSame(20, TailleDePage::depuis($hors), var_export($hors, true) . ' vaut le défaut');
        }
    }

    /** La recherche dynamique lit le choix par cette source, et le total de la sélection s'y aligne. */
    public function testLaRechercheDynamiqueLitLeChoixParLaSourceUnique(): void
    {
        $trait = (string) file_get_contents(__DIR__ . '/../../src/Controller/Admin/ControllerUtilsTrait.php');
        self::assertStringContainsString("\$parPage = \\App\\Service\\Liste\\TailleDePage::depuis(\$payload['parPage'] ?? null);", $trait);
        self::assertStringContainsString('$criteria, $entreprise, $parentContext, $page, $parPage);', $trait);
        self::assertStringContainsString('max(\App\Service\Liste\TailleDePage::CHOIX)', $trait);

        $liste = (string) file_get_contents(__DIR__ . '/../../templates/components/_list_manager.html.twig');
        self::assertStringContainsString("constant('App\\\\Service\\\\Liste\\\\TailleDePage::CHOIX')", $liste, 'Les choix sont rendus par le serveur.');
    }

    /** « Réinitialiser » ramène la taille par défaut ; une autre recherche la garde. */
    public function testReinitialiserRameneLaTailleParDefaut(): void
    {
        $cerveau = (string) file_get_contents(__DIR__ . '/../../assets/controllers/cerveau_controller.js');
        self::assertMatchesRegularExpression('/_handleSearchRequest\(criteria = \{\}, workspaceTabId = null, reinitialiser = false\) \{.*?if \(reinitialiser\) delete etat\.parPage;/s', $cerveau);
        foreach (['search-bar_controller.js', 'search-bar-dialog_controller.js'] as $fichier) {
            self::assertStringContainsString('reinitialiser: true', (string) file_get_contents(__DIR__ . '/../../assets/controllers/' . $fichier), $fichier);
        }
    }
}
