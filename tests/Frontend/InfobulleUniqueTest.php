<?php

namespace App\Tests\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * UNE SEULE INFOBULLE (chantier 3) : les contrôleurs d'infobulle écrits à côté de l'infobulle
 * unique disparaissent, et rien ne les appelle plus.
 *
 * Commit 3a : la barre d'actions (dark-tooltip), le propriétaire du cabinet (owner-tip), le
 * dernier commit (commit-tip) et la description d'un attribut de canevas (tooltip) passent par
 * `infobulle_controller` — contenu DÉCLARÉ (data-infobulle) ou lu dans le `title` (barres
 * d'actions : source « titre », centrée sous le bouton, décision de Serge).
 */
class InfobulleUniqueTest extends TestCase
{
    private const ANCIENS = ['dark-tooltip', 'owner-tip', 'commit-tip', 'tooltip'];

    private static function racine(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function testLesAnciensControleursDInfobulleOntDisparu(): void
    {
        foreach (self::ANCIENS as $nom) {
            self::assertFileDoesNotExist(self::racine() . "/assets/controllers/{$nom}_controller.js", "« $nom » : l'infobulle unique le remplace.");
        }
        self::assertFileDoesNotExist(self::racine() . '/assets/controllers/infobulle_controller.js.bak');
    }

    public function testRienNAppelleUnAncienControleurNiUnAncienAttribut(): void
    {
        $motif = '/data-controller\s*=\s*"[^"]*\b(?:dark-tooltip|owner-tip|commit-tip|tooltip)\b(?!-)[^"]*"'
            . '|dataset\.controller\s*=\s*[\'"](?:tooltip|dark-tooltip|owner-tip|commit-tip)[\'"]'
            . '|data-(?:owner-tip|commit-tip)\b|data-tooltip-(?:content|text)\b|tooltipContent|\bshowTooltip\b/';
        $fautifs = [];
        foreach (['templates', 'assets/controllers'] as $dossier) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::racine() . "/$dossier", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (preg_match('/\.(twig|js)$/', $f->getFilename()) && preg_match($motif, (string) file_get_contents($f->getPathname()), $m)) {
                    $fautifs[] = substr($f->getPathname(), \strlen(self::racine()) + 1) . ' : ' . $m[0];
                }
            }
        }
        self::assertSame([], $fautifs);
    }

    public function testLesBarresDActionsLisentLeTitleCentreeSousLeBouton(): void
    {
        $barre = (string) file_get_contents(self::racine() . '/templates/components/_toolbar.html.twig');
        $dialogue = (string) file_get_contents(self::racine() . '/assets/controllers/dialog-manager_controller.js');
        foreach (['barre du workspace' => $barre, 'barre du dialogue' => $dialogue] as $ou => $source) {
            self::assertMatchesRegularExpression('/data-controller="[^"]*\binfobulle\b[^"]*"/', $source, $ou);
            self::assertStringContainsString('data-infobulle-source-value="titre"', $source, $ou);
            self::assertStringContainsString('data-infobulle-placement-value="bouton"', $source, $ou);
        }
    }
}
