<?php

namespace App\Tests\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * LA DISPOSITION DE LA COLONNE 3 — la liste d'une rubrique (refonte du 2026-10-27).
 *
 * But : donner le plus de place possible aux données. Mesuré à l'étape 0 : à 1 358 × 595,
 * six rangées d'outils empilées au-dessus du tableau ne laissaient voir AUCUNE ligne de
 * Tranche, panneau de Ket ouvert. Les outils passent sur les côtés :
 *   - la barre d'outils devient un RAIL vertical à gauche de la liste, hors de l'entête ;
 *   - Quitter, Actualiser, Sélectionner tout et Paramètres se regroupent dans « Plus » —
 *     regroupement PERMANENT (décision de Serge) : un bouton qui sauterait du rail au menu
 *     au gré de la hauteur ferait bouger l'écran sans action de l'utilisateur. Chacun a un
 *     double (× de l'onglet, touche R, case de l'entête du tableau). Supprimer reste au rail ;
 *   - la barre d'état, de pagination et des totaux descend au BAS de la liste, sur toute sa
 *     largeur ; le rail s'arrête au-dessus d'elle (`--jsb-barre-bas-h`, une seule hauteur
 *     pour les deux).
 *
 * Les collections en DIALOGUE gardent leur disposition (divergence assumée, chantier 22).
 */
class DispositionColonneTroisTest extends TestCase
{
    private const VUE = __DIR__ . '/../../templates/components/_view_manager.html.twig';
    private const BARRE = __DIR__ . '/../../templates/components/_toolbar.html.twig';
    private const FEUILLE = __DIR__ . '/../../assets/styles/app.css';
    private const CONTROLEUR = __DIR__ . '/../../assets/controllers/toolbar_controller.js';

    /** Les quatre outils regroupés, dans l'ordre du menu — et eux seuls. */
    private const DANS_PLUS = ['bttoutcocher', 'btrecharger', 'btparametres', 'btquitter'];

    /** Ce qui reste au rail : les actions sur la sélection, Supprimer compris. */
    private const AU_RAIL = ['btajouter', 'btmodifier', 'btouvrir', 'btsupprimer'];

    private static function feuille(): string
    {
        // Commentaires retirés : une déclaration mise en commentaire ne compte pas.
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::FEUILLE));
    }

    private static function regle(string $selecteur): string
    {
        preg_match('/(?:^|\})\s*' . preg_quote($selecteur, '/') . '\s*\{([^}]*)\}/m', self::feuille(), $m);

        return $m[1] ?? '';
    }

    private static function barre(): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true); // balises Twig (`twig:UX:Icon`) : inconnues, mais lisibles
        // Les commentaires Twig (y compris DANS une balise ouvrante) sont retirés d'abord :
        // laissés, ils feraient lire au parseur des attributs fantômes.
        $source = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents(self::BARRE));
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $source);
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

    public function testLaBarreDOutilsQuitteLEnteteEtDevientUnRail(): void
    {
        $vue = (string) file_get_contents(self::VUE);

        preg_match('/<div class="sticky-header.*?\{# =+ #\}\s*\{# SECTION 2/s', $vue, $entete);
        self::assertNotEmpty($entete, 'L’entête de la rubrique est repérable.');
        self::assertStringNotContainsString('_toolbar.html.twig', $entete[0], 'La barre d’outils ne vit plus dans l’entête.');

        // Le rail et les panneaux partagent une zone, référente de leur position.
        self::assertMatchesRegularExpression(
            '/class="jsb-zone-liste"[^>]*>\s*\{\{ include\("components\/_toolbar\.html\.twig"\) \}\}.*?data-view-manager-target="tabContentContainer"/s',
            $vue,
        );

        $racine = self::barre()->query('//*[@role="toolbar"]')->item(0);
        self::assertNotNull($racine);
        self::assertStringContainsString('context-toolbar--rail', $racine->getAttribute('class'));
        self::assertSame('vertical', $racine->getAttribute('aria-orientation'));
        foreach (self::barre()->query('//*[@role="separator"][not(ancestor::*[@role="menu"])]') as $separateur) {
            self::assertSame('horizontal', $separateur->getAttribute('aria-orientation'), 'Dans un rail vertical, un séparateur est horizontal.');
        }
    }

    public function testPlusRegroupeQuatreOutilsEtRienDAutre(): void
    {
        $x = self::barre();

        $bouton = $x->query('//*[@data-toolbar-target="btplus"]')->item(0);
        self::assertNotNull($bouton, 'Le rail porte un bouton « Plus ».');
        self::assertSame('menu', $bouton->getAttribute('aria-haspopup'));
        self::assertSame('false', $bouton->getAttribute('aria-expanded'));
        self::assertNotSame('', $bouton->getAttribute('aria-label'));

        $menu = $x->query('//*[@role="menu"]')->item(0);
        self::assertNotNull($menu);
        // Masqué par l'attribut `hidden` : aucun `style=` en ligne (politique de sécurité).
        self::assertTrue($menu->hasAttribute('hidden'));
        self::assertFalse($menu->hasAttribute('style'));
        self::assertSame($menu->getAttribute('id'), $bouton->getAttribute('aria-controls'), 'Le bouton désigne son menu.');

        $cibles = [];
        foreach ($x->query('.//*[@role="menuitem"]', $menu) as $entree) {
            $cibles[] = $entree->getAttribute('data-toolbar-target');
            // Un menu se lit : chaque entrée porte son libellé en TEXTE, pas seulement une icône.
            self::assertNotSame('', trim($entree->textContent), 'Une entrée de « Plus » sans libellé visible.');
            // Le MÊME geste qu'avant le regroupement : l'événement du Cerveau ne change pas.
            self::assertSame('click->toolbar#notify', $entree->getAttribute('data-action'));
        }
        self::assertSame(self::DANS_PLUS, $cibles);

        foreach (self::AU_RAIL as $cible) {
            $element = $x->query('//*[@data-toolbar-target="' . $cible . '"]')->item(0);
            self::assertNotNull($element, "$cible existe toujours.");
            self::assertSame(0, $x->query('ancestor::*[@role="menu"]', $element)->length, "$cible reste au rail, jamais dans « Plus ».");
        }
    }

    public function testPlusPasseParLaCoquilleDeMenu(): void
    {
        $controleur = (string) file_get_contents(self::CONTROLEUR);
        self::assertMatchesRegularExpression("#from\\s+'\\./menu-contextuel\\.js'#", $controleur);
        self::assertStringContainsString('new MenuContextuel(', $controleur);
    }

    public function testLeRailEtLaBarreDuBasPartagentUneHauteur(): void
    {
        $zone = self::regle('.jsb-zone-liste');
        self::assertMatchesRegularExpression('/position:\s*relative;/', $zone);
        self::assertMatchesRegularExpression('/--jsb-barre-bas-h:\s*\d/', $zone);
        self::assertMatchesRegularExpression('/--jsb-rail-w:\s*\d/', $zone);

        $rail = self::regle('.context-toolbar--rail');
        self::assertMatchesRegularExpression('/position:\s*absolute;/', $rail);
        self::assertMatchesRegularExpression('/bottom:\s*var\(--jsb-barre-bas-h\);/', $rail);
        self::assertMatchesRegularExpression('/width:\s*var\(--jsb-rail-w\);/', $rail);

        // La liste réserve la largeur du rail et la hauteur de la barre du bas.
        $liste = self::regle('.jsb-list-manager');
        self::assertMatchesRegularExpression('/display:\s*grid;/', $liste);
        self::assertMatchesRegularExpression('/grid-template-columns:\s*var\(--jsb-rail-w\)/', $liste);
        self::assertMatchesRegularExpression('/grid-template-rows:[^;]*var\(--jsb-barre-bas-h\);/', $liste);

        $barreDuBas = self::regle('.jsb-list-manager > .jsb-list-controls-bar');
        self::assertMatchesRegularExpression('/grid-column:\s*1\s*\/\s*-1;/', $barreDuBas, 'La barre du bas court sur toute la largeur, sous le rail.');
        self::assertMatchesRegularExpression('/grid-row:\s*-2;/', $barreDuBas, 'La barre du bas est la DERNIÈRE rangée.');
    }

    /**
     * LES MENUS DU RAIL PASSENT AU-DESSUS DE L'ENTÊTE DU TABLEAU.
     *
     * Le rail fait un contexte d'empilement : son z-index plafonne « Plus » et les menus de
     * famille, qui s'ouvrent par-dessus la colonne du tableau. Mesuré à 5 : « Paramètres »
     * et « Quitter » étaient sous l'entête collante (100), inatteignables.
     */
    public function testLeRailPasseAuDessusDeLEnteteDuTableau(): void
    {
        preg_match('/z-index:\s*(\d+);/', self::regle('.context-toolbar--rail'), $rail);
        preg_match('/z-index:\s*(\d+);/', self::regle('thead.sticky-header'), $entete);
        self::assertNotEmpty($rail);
        self::assertNotEmpty($entete);
        self::assertGreaterThan((int) $entete[1], (int) $rail[1]);
        // …mais sous l'entête de la rubrique (titre, onglets, recherche), au-dessus de lui.
        self::assertLessThan(1020, (int) $rail[1]);
    }

    /** Un rail qui se dit vertical se parcourt par ↑ et ↓ (pattern Toolbar de l'APG). */
    public function testLeRailVerticalSeParcourtParLesFlechesHautEtBas(): void
    {
        $barre = (string) file_get_contents(__DIR__ . '/../../assets/controllers/barre-actions.js');
        self::assertStringContainsString("from './barre-clavier.js'", $barre);
        self::assertStringContainsString("getAttribute('aria-orientation') === 'vertical'", $barre);
    }

    /** Un panneau sans liste (squelette, échec) se décale du rail au lieu d'être masqué. */
    public function testUnPanneauSansListeNEstPasMasqueParLeRail(): void
    {
        self::assertMatchesRegularExpression(
            '/:not\(:has\(> \.jsb-list-manager\)\)\s*\{\s*padding-left:\s*var\(--jsb-rail-w\);/',
            self::feuille(),
        );
    }
}
