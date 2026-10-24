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
            . '|data-(?:owner-tip|commit-tip)\b|data-tooltip-(?:content|text)\b|tooltipContent|\bshowTooltip\b'
            // Commit 3b : l'ancienne bulle du relevé de compte (contrôleur SOA + script des pages autonomes).
            . '|data-soa-tip\b|soa-section-tip\b|soaTip\b'
            // Commit 3c : le chat de Ket et le graphique de production.
            . '|data-(?:ctx-tip|ctx-fiche|fic-tip|msg-contextes|mic-tip-\w+)\b|_ctxTip|_micTip|db-prod-tip|commit-tip/';
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

    /**
     * Commit 3b : les styles de la bulle sont écrits UNE fois, dans leur fichier, que lient
     * l'application ET les pages autonomes (sans app.css ni Stimulus).
     */
    public function testLesStylesDeLaBulleSontDansLeurFichierLiePartout(): void
    {
        self::assertStringNotContainsString('.jsb-ctx-tip', (string) file_get_contents(self::racine() . '/assets/styles/app.css'), 'Les styles de la bulle vivent dans styles/infobulle.css.');
        self::assertStringContainsString('.jsb-ctx-tip', (string) file_get_contents(self::racine() . '/assets/styles/infobulle.css'));
        foreach (['templates/segments/_feuilles_de_l_application.html.twig', 'templates/admin/soa/soa_client_standalone.html.twig', 'templates/public/soa/soa_client_public.html.twig'] as $page) {
            self::assertStringContainsString("asset('styles/infobulle.css')", (string) file_get_contents(self::racine() . '/' . $page), $page);
        }
        foreach (['templates/admin/soa/soa_client_standalone.html.twig', 'templates/public/soa/soa_client_public.html.twig'] as $page) {
            self::assertStringContainsString("importmap('infobulles-autonomes'", (string) file_get_contents(self::racine() . '/' . $page), $page);
        }
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
