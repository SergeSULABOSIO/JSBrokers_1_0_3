<?php

namespace App\Tests\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * LES 8 MENUS DU TABLEAU DE BORD SUR LA COQUILLE COMMUNE (chantier 3, commit 5 — ajout 3 de
 * Serge : les menus au clavier).
 *
 * - Plus de placement ni de fermeture écrits à la main dans `assets/app.js` : la coquille
 *   (`menu-contextuel.js`) ouvre, place, ferme et gère le clavier.
 * - Chaque ligne qui porte un menu se focalise au clavier (le <summary> d'une ligne dépliable,
 *   ou la ligne elle-même) : la touche Menu et Maj+F10 y ouvrent le menu.
 * - Chaque menu se nomme (aria-label), en rôles menu/menuitem ; une entrée refusée le dit
 *   (aria-disabled), au lieu d'être seulement pâlie.
 */
class MenusDuTableauDeBordTest extends TestCase
{
    private const MENUS = [
        'dbRenewCtxMenu' => '_renewals', 'dbDepCtxMenu' => '_depenses', 'dbSinCtxMenu' => '_sinistres',
        'dbTaskCtxMenu' => '_block_tasks', 'dbPisteCtxMenu' => '_block_pistes', 'dbBordCtxMenu' => '_block_bordereaux',
        'dbNoteCtxMenu' => '_block_notes', 'dbInviteCtxMenu' => '_block_users',
    ];

    private static function racine(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function testPlusDePlacementNiDeFermetureALaMain(): void
    {
        $app = (string) file_get_contents(self::racine() . '/assets/app.js');
        self::assertDoesNotMatchRegularExpression('/var mw = \d+, mh = \d+;/', $app, 'Placement : la coquille.');
        self::assertDoesNotMatchRegularExpression("/addEventListener\\('click', function hide\\(\\)/", $app, 'Fermeture : la coquille.');
        self::assertDoesNotMatchRegularExpression("/getElementById\\('db\\w+CtxMenu'\\);\\s*if \\(menu\\) menu\\.style\\.display = 'none';/", $app, 'Fermer = fermerMenuDuTableauDeBord().');
        self::assertSame(8, preg_match_all('/ouvrirMenuDuTableauDeBord\(menu, event, el\);/', $app), 'Les 8 menus s’ouvrent par la coquille.');
    }

    public function testChaqueMenuSeNommeEtDitSesEntreesRefusees(): void
    {
        foreach (self::MENUS as $id => $gabarit) {
            $source = (string) file_get_contents(self::racine() . "/templates/components/dashboard/$gabarit.html.twig");
            self::assertMatchesRegularExpression('/<nav id="' . $id . '" class="context-menu" aria-label="[^"]+"/', $source, "$id : un nom.");
            $nav = substr($source, (int) strpos($source, '<nav id="' . $id . '"'));
            $nav = substr($nav, 0, (int) strpos($nav, '</nav>'));
            self::assertStringContainsString('role="menu"', $nav, "$id : rôle menu.");
            self::assertGreaterThan(0, substr_count($nav, 'role="menuitem"'), "$id : entrées en menuitem.");
            self::assertSame(substr_count($nav, '<li'), substr_count($nav, 'role="menuitem"') + substr_count($nav, 'role="separator"'), "$id : toute entrée a son rôle.");
        }
        $app = (string) file_get_contents(self::racine() . '/assets/app.js');
        self::assertSame(
            preg_match_all("/style\\.opacity = '0\\.38';/", $app),
            preg_match_all("/setAttribute\\('aria-disabled', 'true'\\);/", $app),
            'Une entrée pâlie est aussi refusée pour le clavier (aria-disabled).',
        );
    }

    public function testLesInvitesSeFocalisentAuClavier(): void
    {
        $source = (string) file_get_contents(self::racine() . '/templates/components/dashboard/_users_list.html.twig');
        self::assertSame(2, preg_match_all('/data-tableau-de-bord-fonction-param="dbInviteCtxOpen"/', $source));
        self::assertSame(2, preg_match_all('/class="[^"]*db-users-(?:current|member)[^"]*"\s+tabindex="0"/', $source), 'Les lignes d’invités se focalisent.');
        self::assertSame(2, preg_match_all('/role="group" aria-label="[^"]+ — actions : touche Menu ou Maj\+F10"/', $source), 'Une ligne d’invité se nomme et annonce son menu.');
    }

    /** Le contour du focus se voit : cobalt plein (≥ 3:1, WCAG 1.4.11), pas un cobalt à 35 %. */
    public function testLeFocusDesLignesSeVoit(): void
    {
        $css = (string) file_get_contents(self::racine() . '/assets/styles/app.css');
        self::assertStringContainsString('.db-ass-details > summary:focus-visible { outline: 2px solid var(--cobalt);', $css);
        self::assertStringContainsString('.db-users-current:focus-visible, .db-users-member:focus-visible { outline: 2px solid var(--cobalt);', $css);
    }
}
