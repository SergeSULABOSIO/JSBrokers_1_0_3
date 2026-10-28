<?php

namespace App\Tests\Frontend;

use App\Services\Search\ReversementScope;
use App\Services\Canvas\Provider\List\ReversementRetroAgentListCanvasProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA LIGNE DES FILTRES ACTIFS (refonte de la colonne 3, C2).
 *
 * Sous la recherche, tout ce qui masque des données se lit EN TEXTE : chips (« Prime :
 * Impayée »), terme, critères avancés, « Mon portefeuille », et les défauts posés d'office,
 * marqués « par défaut ». Elle remplace le repli « Filtres N » et son volet, qui cachaient
 * justement ce qu'il fallait lire (BIC Cristal : l'état réel visible).
 *
 * La décision est éprouvée en node (tests/js/search-bar-filtres.test.mjs) ; ce test garde
 * le montage : le serveur rend la ligne et son modèle, le navigateur clone et pose des
 * textes ; « Tout retirer » suit la règle du chantier 6, dite par le serveur.
 */
class LigneDesFiltresActifsTest extends KernelTestCase
{
    private const BARRE = __DIR__ . '/../../templates/components/_search_bar.html.twig';
    private const CONTROLEUR = __DIR__ . '/../../assets/controllers/search-bar_controller.js';
    private const REGLE = __DIR__ . '/../../assets/controllers/search-bar-filtres.js';

    public function testLeServeurRendLaLigneEtSonModele(): void
    {
        $barre = (string) file_get_contents(self::BARRE);

        self::assertMatchesRegularExpression(
            '/<div class="jsb-filtres-actifs" role="region" aria-label="Filtres actifs"\s+data-search-bar-target="ligne" hidden>/',
            $barre,
            'La ligne existe dès le rendu, masquée tant que rien ne filtre.',
        );
        // Le navigateur CLONE un modèle : il ne fabrique pas de HTML.
        self::assertMatchesRegularExpression('/<template data-search-bar-target="modeleFiltre">\s*<li class="jsb-filtre-actif">/', $barre);
        foreach (['jsb-filtre-actif__texte', 'jsb-filtre-actif__defaut', 'jsb-filtre-actif__retirer'] as $piece) {
            self::assertStringContainsString($piece, $barre);
        }
        self::assertStringContainsString('data-action="click->search-bar#retirerTout"', $barre);
        // « Tout retirer » : le SERVEUR dit si « Mon portefeuille » part aussi (chantier 6).
        self::assertStringContainsString("data-search-bar-portefeuille-retirable-value=\"{{ workspace_est_administrateur() ? 'true' : 'false' }}\"", $barre);

        // L'ancien repli a disparu : ni pastille « Filtres N », ni volet.
        foreach (['jsb-filters-chip', 'jsb-search-bar__popover', 'data-search-bar-target="summary"'] as $ancien) {
            self::assertStringNotContainsString($ancien, $barre);
        }
    }

    public function testLeNavigateurClonePoseDesTextesEtSuitLaRegle(): void
    {
        $controleur = (string) file_get_contents(self::CONTROLEUR);

        self::assertStringContainsString('this.modeleFiltreTarget.content.firstElementChild.cloneNode(true)', $controleur);
        self::assertStringContainsString(".textContent = texte;", $controleur);
        self::assertStringNotContainsString('innerHTML', $controleur, 'Aucun HTML fabriqué par chaîne : les textes sont posés par textContent.');
        // Le × de la recherche et « Tout retirer » (qui l'appelle) suivent la MÊME règle,
        // appliquée à ce que la ligne AFFICHE (jamais la maille de lecture ni l'imposé).
        self::assertStringContainsString('this._retirer(clesARetirer(this.filtresAffiches, this.portefeuilleRetirableValue));', $controleur);
        self::assertMatchesRegularExpression('/retirerTout\(\) \{[^}]*this\.resetAllFilters\(\);/', $controleur);
        // Les critères IMPOSÉS par le serveur lui sont lus — et le serveur les déclare.
        self::assertStringContainsString('dataset.criteresImposes', $controleur);
        self::assertStringContainsString("data-criteres-imposes='{{ (criteresImposes|default([]))|json_encode|e('html_attr') }}'", (string) file_get_contents(__DIR__ . '/../../templates/components/_list_manager.html.twig'));
        // La liste visée est celle de l'ONGLET concerné, pas la première visible.
        self::assertStringContainsString('listes.find((l) => l.id === this.ongletActif)', $controleur);

        $regle = (string) file_get_contents(self::REGLE);
        self::assertStringNotContainsString('SEUIL_COMPTEUR', $regle);
        self::assertStringNotContainsString('construireResumeFiltres', $regle);
    }

    /** « Virement » est la maille de lecture des Rétros, pas un filtre : la ligne ne l'annonce pas. */
    public function testLaMailleDeLectureNEstPasUnFiltre(): void
    {
        self::bootKernel();
        $groupes = static::getContainer()->get(ReversementRetroAgentListCanvasProvider::class)->getCanvas()['filtres_predefinis'];
        $virement = array_values(array_filter($groupes, static fn (array $g) => $g['critere'] === ReversementScope::CLE_VIREMENT))[0];
        self::assertTrue($virement['presentation'] ?? false);

        $gabarit = (string) file_get_contents(__DIR__ . '/../../templates/components/_list_manager.html.twig');
        self::assertStringContainsString('{% if groupe.presentation|default(false) %}data-preset-presentation="true"{% endif %}', $gabarit);
    }
}
