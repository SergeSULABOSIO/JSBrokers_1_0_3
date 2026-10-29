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
 *   - Sélectionner tout, Actualiser, Paramètres et Quitter sont AU RAIL autant qu'il en tient ;
 *     ceux qui n'ont pas de place restent dans « Plus », qui disparaît quand tous tiennent
 *     (décision de Serge, 2026-10-28 — elle remplace le regroupement permanent du plan).
 *     Supprimer reste toujours au rail ;
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

    /**
     * LES OUTILS AU RAIL AUTANT QU'IL EN TIENT : chacun existe deux fois — bouton du rail et
     * entrée de « Plus » —, dans le même ordre et avec le MÊME événement du Cerveau ; la règle
     * (rail-outils.js) décide lequel des deux est affiché, à chaque changement de sélection.
     */
    public function testLesOutilsSontAuRailAutantQuIlsTiennent(): void
    {
        $x = self::barre();
        $evenements = static function (\DOMNodeList $noeuds): array {
            $liste = [];
            foreach ($noeuds as $n) {
                $liste[(int) $n->getAttribute('data-outil')] = $n->getAttribute('data-toolbar-event-name-param');
            }
            ksort($liste);

            return $liste;
        };
        $auRail = $evenements($x->query('//*[@data-toolbar-target="outilsRail"]//button[@data-outil]'));
        $dansPlus = $evenements($x->query('//*[@role="menu"]//*[@role="menuitem"][@data-outil]'));
        self::assertSame(
            ['ui:toolbar.select-all-request', 'ui:toolbar.refresh-request', 'ui:toolbar.settings-request', 'ui:toolbar.close-request'],
            $auRail,
        );
        self::assertSame($auRail, $dansPlus, 'Un outil fait la même chose au rail et dans « Plus ».');
        foreach ($x->query('//*[@data-toolbar-target="outilsRail"]//button') as $bouton) {
            self::assertNotSame('', $bouton->getAttribute('aria-label'));
        }

        // LE PIED EST ANCRÉ EN BAS : outils et « Plus » ne bougent pas quand la sélection fait
        // apparaître ou disparaître des actions au-dessus (« Sélectionner tout » est une bascule :
        // son second clic tombait sinon sur l'outil remonté — parfois Quitter).
        $pied = $x->query('//*[@data-toolbar-target="piedRail"]')->item(0);
        self::assertNotNull($pied);
        foreach (['outilsRail', 'btplus'] as $cible) {
            $element = $x->query('//*[@data-toolbar-target="' . $cible . '"]')->item(0);
            self::assertSame(1, $x->query('ancestor::*[@data-toolbar-target="piedRail"]', $element)->length, "$cible est dans le pied.");
        }
        foreach (['btajouter', 'btsupprimer', 'specificActionsContainer'] as $cible) {
            $element = $x->query('//*[@data-toolbar-target="' . $cible . '"]')->item(0);
            self::assertSame(0, $x->query('ancestor::*[@data-toolbar-target="piedRail"]', $element)->length, "$cible est au-dessus du pied.");
        }
        self::assertMatchesRegularExpression('/margin-top:\s*auto;/', self::regle('.context-toolbar__pied'));

        $controleur = (string) file_get_contents(self::CONTROLEUR);
        self::assertStringContainsString("from './rail-outils.js'", $controleur);
        // La sélection change les boutons : la place des outils se décide à nouveau.
        self::assertMatchesRegularExpression('/updateSpecificActionButtons\(actionsVisibles\([^;]*;\s*(?:\/\/[^\n]*\n\s*)*(?:fermerInfobulle\(this\.element\);\s*(?:\/\/[^\n]*\n\s*)*)?this\._placerLesOutils\(\);/', $controleur);
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
        self::assertMatchesRegularExpression('/--jsb-rail-w:\s*(?:\d|calc\()/', $zone);

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

        // Le rail des chips aussi (C4) : une barre d'outils verticale, la même règle des flèches.
        $liste = (string) file_get_contents(__DIR__ . '/../../templates/components/_list_manager.html.twig');
        self::assertMatchesRegularExpression('/class="jsb-rail-chips" role="toolbar" aria-orientation="vertical"(?:[^>"]|"[^"]*")*data-action="[^"]*keydown->rail-chips#parcourir"/', $liste);
        $rail = (string) file_get_contents(__DIR__ . '/../../assets/controllers/rail-chips_controller.js');
        self::assertStringContainsString("import { indexVise } from './barre-clavier.js';", $rail);
        self::assertStringContainsString('indexVise(event.key, index, boutons.length, true)', $rail);
    }

    /**
     * LE TACTILE (C4) : ni survol ni infobulle au doigt — chaque bouton des DEUX rails porte son
     * libellé court (`data-libelle`), affiché sous l'icône par `(hover: none), (pointer: coarse)`.
     * Un bouton refusé dit sa raison à l'APPUI (au doigt, il ne la disait jamais).
     */
    public function testAuDoigtChaqueBoutonDesRailsSeLitEtUnRefusSExplique(): void
    {
        foreach (self::barre()->query('//button[contains(concat(" ", normalize-space(@class), " "), " btn ")]') as $bouton) {
            self::assertNotSame('', $bouton->getAttribute('data-libelle'), 'Bouton du rail sans libellé : ' . $bouton->getAttribute('aria-label'));
        }
        $liste = (string) file_get_contents(__DIR__ . '/../../templates/components/_list_manager.html.twig');
        self::assertSame(2, substr_count($liste, 'data-libelle="'), 'Le bouton « Vue » et chaque bouton de groupe du rail des chips.');
        $actions = (string) file_get_contents(__DIR__ . '/../../assets/controllers/barre-actions.js');
        self::assertSame(2, substr_count($actions, 'button.dataset.libelle = '), 'Actions et familles d’actions rendues par BarreActions.');

        self::assertMatchesRegularExpression('/@media \(hover: none\), \(pointer: coarse\) \{\s*\.jsb-zone-liste \{ --jsb-rail-w:/', self::feuille());
        self::assertStringContainsString('content: attr(data-libelle);', self::feuille());

        self::assertStringContainsString('expliquerUnRefus(button);', (string) file_get_contents(self::CONTROLEUR));
        self::assertStringContainsString('if (bouton) expliquerUnRefus(bouton);', $actions);
    }

    /**
     * LE RAIL DÉFILE EN DERNIER RECOURS, ET LE DIT (décision de Serge).
     *
     * Quand même les actions ne tiennent plus (les outils de la rubrique sont déjà partis dans
     * « Plus »), la zone du rail défile. Les flèches sont des aides à la souris, décoratives pour l'assistance — au clavier,
     * ↑/↓ parcourent le rail et le navigateur fait défiler jusqu'au bouton focalisé. Elles
     * ne doivent donc pas devenir des arrêts clavier de la barre.
     */
    public function testLeRailDefileEnDernierRecours(): void
    {
        $defilant = self::regle('.context-toolbar__defilant');
        self::assertMatchesRegularExpression('/overflow-y:\s*auto;/', $defilant);
        self::assertMatchesRegularExpression('/min-height:\s*0;/', $defilant);
        // Toute la largeur du rail : centrée, la zone rognait le contour de focus (mesuré).
        self::assertMatchesRegularExpression('/align-self:\s*stretch;/', $defilant);
        // Le bouton focalisé est amené hors des flèches.
        self::assertMatchesRegularExpression('/scroll-padding-block:\s*\d+px;/', $defilant);
        // Un bouton ne s'écrase pas pour tenir (mesuré : « Plus » à 13 px de haut).
        self::assertMatchesRegularExpression('/\.context-toolbar__defilant > \*,\s*\.context-toolbar--rail \.context-toolbar__groupe > \*,\s*\.context-toolbar__pied > \*\s*\{\s*flex-shrink:\s*0;/', self::feuille());
        // Une flèche est une cible : 24 px au moins (WCAG 2.5.8).
        preg_match('/height:\s*(\d+)px;/', self::regle('.context-toolbar__fleche'), $hauteur);
        self::assertGreaterThanOrEqual(24, (int) ($hauteur[1] ?? 0));

        $x = self::barre();
        $zone = $x->query('//*[@data-toolbar-target="defilant"]')->item(0);
        self::assertNotNull($zone);
        // Tous les boutons du rail sont DANS la zone qui défile, « Plus » compris.
        foreach (['btajouter', 'btsupprimer', 'btplus', 'specificActionsContainer'] as $cible) {
            $element = $x->query('//*[@data-toolbar-target="' . $cible . '"]')->item(0);
            self::assertSame(1, $x->query('ancestor::*[@data-toolbar-target="defilant"]', $element)->length, "$cible défile avec le rail.");
        }

        $fleches = $x->query('//*[contains(concat(" ", normalize-space(@class), " "), " context-toolbar__fleche ")]');
        self::assertSame(2, $fleches->length);
        foreach ($fleches as $fleche) {
            self::assertSame('span', $fleche->nodeName, 'Une flèche n’est pas un bouton : elle deviendrait un arrêt clavier de la barre.');
            self::assertSame('true', $fleche->getAttribute('aria-hidden'));
            // En surcouche : hors de la zone qui défile, et sans place dans le flux.
            self::assertSame(0, $x->query('ancestor::*[@data-toolbar-target="defilant"]', $fleche)->length);
        }
        self::assertMatchesRegularExpression('/position:\s*absolute;/', self::regle('.context-toolbar__fleche'));
    }

    /**
     * LE SURVOL D'UN BOUTON DU RAIL (décision de Serge, 2026-10-28) : léger grossissement et
     * glissement vers la droite — et RIEN pour qui a demandé moins de mouvement.
     */
    public function testLeSurvolDuRailBougeSaufMouvementReduit(): void
    {
        $feuille = self::feuille();
        // Il grossit DEPUIS SON BORD GAUCHE : il avance vers la droite sans que ce bord bouge
        // (une translation faisait clignoter le survol sous un pointeur posé sur le bord).
        self::assertMatchesRegularExpression('/\.context-toolbar--rail \.btn\s*\{[^}]*transform-origin:\s*left center;/', $feuille);
        self::assertMatchesRegularExpression(
            '/\.context-toolbar--rail \.btn:not\(\[aria-disabled="true"\]\):not\(:focus-visible\):hover\s*\{[^}]*transform:\s*scale\(1\.\d+\);/',
            $feuille,
        );
        self::assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\s*\{\s*\.context-toolbar--rail \.btn\s*\{\s*transition:\s*none;\s*\}\s*\.context-toolbar--rail \.btn:not\(\[aria-disabled="true"\]\):not\(:focus-visible\):hover\s*\{\s*transform:\s*none;/',
            $feuille,
        );
    }

    /**
     * LA POIGNÉE DE LA COLONNE 2 NE RECOUVRE AUCUN BOUTON DU RAIL (Serge, 2026-10-28).
     *
     * Centrée sur le bord gauche du rail, la poignée « Afficher / Masquer le panneau » déborde
     * de sa demi-largeur, et sa zone de clic s'agrandit encore de son `::after` (cible WCAG) :
     * mesuré, jusqu'à 22 px dans le rail — Modifier, Ouvrir, Supprimer ne répondaient plus sur
     * leur bord gauche. Le rail se retire d'autant, plus une marge ; les dimensions sont LUES
     * dans la feuille de la poignée, pour que le retrait les suive.
     */
    public function testLaPoigneeDeLaColonne2NeRecouvreAucunBoutonDuRail(): void
    {
        $menu = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(__DIR__ . '/../../assets/styles/interactive-menu.css'));
        preg_match('/\.ws-edge-handle\s*\{[^}]*?\bwidth:\s*(\d+)px;/', $menu, $largeur);
        preg_match('/\.ws-edge-handle::after\s*\{[^}]*?inset:\s*-(\d+)px;/', $menu, $agrandi);
        self::assertNotEmpty($largeur);
        self::assertNotEmpty($agrandi);
        $empietement = (int) $largeur[1] / 2 + (int) $agrandi[1];

        preg_match('/--jsb-rail-retrait:\s*(\d+)px;/', self::regle('.jsb-zone-liste'), $retrait);
        self::assertNotEmpty($retrait, 'Le rail déclare son retrait.');
        // Le bouton (34,8 px), centré dans la place qui reste (44 px moins la bordure), commence
        // 4 px après le retrait : il doit commencer AU-DELÀ de la zone de clic de la poignée.
        self::assertGreaterThanOrEqual($empietement + 1, (int) $retrait[1] + 4, 'Le bouton commence après la zone de clic de la poignée.');
        // Le retrait s'AJOUTE à la largeur du rail (la colonne que la liste lui réserve suit),
        // et les boutons commencent après lui.
        self::assertMatchesRegularExpression('/--jsb-rail-w:\s*calc\(\s*\d+px\s*\+\s*var\(--jsb-rail-retrait\)\s*\);/', self::regle('.jsb-zone-liste'));
        self::assertMatchesRegularExpression('/padding:\s*\d+px\s+0\s+\d+px\s+var\(--jsb-rail-retrait\);/', self::regle('.context-toolbar__defilant'));
    }

    /**
     * LE RAIL DES CHIPS (C3), à droite : même règle que le rail d'outils face à la poignée de la
     * colonne 2 — la poignée de Ket (même `.ws-edge-handle`, centrée sur le bord gauche de la
     * colonne 4) déborde d'autant dans la colonne 3, et le rail s'en retire du même retrait
     * (demande de Serge, 2026-10-28). Sa colonne a la largeur du rail d'outils, retrait compris.
     */
    public function testLaPoigneeDeKetNeRecouvreAucunBoutonDuRailDesChips(): void
    {
        $menu = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(__DIR__ . '/../../assets/styles/interactive-menu.css'));
        self::assertMatchesRegularExpression('/\.ws-col4-handle\s*\{[^}]*right:\s*var\(--ws-col4-width\);[^}]*translate\(50%/', $menu, 'La poignée de Ket est centrée sur le bord gauche de la colonne 4.');

        self::assertMatchesRegularExpression('/padding:\s*\d+px\s+var\(--jsb-rail-retrait\)\s+\d+px\s+0;/', self::regle('.jsb-list-manager > .jsb-rail-chips'), 'Les boutons commencent avant le retrait droit.');
        self::assertMatchesRegularExpression('/grid-template-columns:\s*var\(--jsb-rail-w\)\s+minmax\(0,\s*1fr\)\s+var\(--jsb-rail-w\);/', self::regle('.jsb-list-manager:has(> .jsb-rail-chips)'));
    }

    /**
     * LA RANGÉE DES CHIPS DEVIENT LE PANNEAU DU RAIL — le même élément, jamais un doublon : un bouton
     * par groupe, qui la désigne (`aria-controls`) ; jamais en dialogue ; aucune transition sous
     * `prefers-reduced-motion`.
     */
    public function testLaRangeeDesChipsDevientLePanneauDuRail(): void
    {
        $liste = (string) file_get_contents(__DIR__ . '/../../templates/components/_list_manager.html.twig');
        self::assertMatchesRegularExpression("/set railDesChips = usage\|default\('generic'\) != 'dialog' and listeCanvas\.filtres_predefinis/", $liste);
        // Les valeurs entre guillemets sont sautées : `click->rail-chips#…` contient un « > ».
        self::assertMatchesRegularExpression('/class="jsb-preset-filters-bar" id="\{\{ idPanneauChips \}\}"(?:[^>"]|"[^"]*")*data-rail-chips-target="panneau"/s', $liste);
        self::assertMatchesRegularExpression('/\{% for groupe in listeCanvas\.filtres_predefinis %\}\s*\{% set titreDuGroupe[^%]*%\}\s*<button type="button" class="jsb-rail-chips__bouton"(?:[^>"]|"[^"]*")*aria-controls="\{\{ idPanneauChips \}\}"/s', $liste);
        self::assertSame(1, substr_count($liste, 'class="jsb-preset-filters-bar"'), 'Une seule rangée de chips : le panneau.');

        self::assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\)\s*\{\s*\.jsb-rail-chips__bouton,/', self::feuille());
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
