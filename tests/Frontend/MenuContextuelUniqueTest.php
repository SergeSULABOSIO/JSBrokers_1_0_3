<?php

namespace App\Tests\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * UNE SEULE COQUILLE DE MENU (chantier 3, commit 4) : ouvrir au curseur ou sous un élément, les
 * flèches, Début/Fin, Entrée, Échap, le retour du focus, la fermeture au clic dehors, au
 * défilement et au redimensionnement — écrits UNE fois, dans `menu-contextuel.js`.
 *
 * Chaque écran garde son menu (rendu par le serveur) et ses actions ; il ne réécrit plus ni le
 * placement ni le clavier. Les cinq menus migrés : cartes de cabinet, relevé de compte,
 * console, listes de l'espace de travail, bulles du chat de Ket.
 */
class MenuContextuelUniqueTest extends TestCase
{
    private const MIGRES = [
        'entreprise-context-menu_controller.js',
        'soa-context-menu_controller.js',
        'console-context-menu_controller.js',
        'context-menu_controller.js',
        'assistant-chat_controller.js',
    ];

    private static function source(string $fichier): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/assets/controllers/' . $fichier);
    }

    public function testChaqueMenuPasseParLaCoquille(): void
    {
        foreach (self::MIGRES as $fichier) {
            self::assertMatchesRegularExpression("#import \\{[^}]*\\bMenuContextuel\\b[^}]*\\} from './menu-contextuel\\.js';#", self::source($fichier), "$fichier : la coquille commune.");
        }
    }

    public function testAucunMenuNeReecritLeClavierNiLePlacement(): void
    {
        $fautifs = [];
        foreach (self::MIGRES as $fichier) {
            $source = self::source($fichier);
            // Le chat garde un AUTRE menu (l'entête, bouton ⋮ qui se déplie en plein écran) :
            // on ne lit que la partie des bulles.
            if ($fichier === 'assistant-chat_controller.js') {
                $debut = strpos($source, '// ═══ Actions d\'une bulle');
                $fin = strpos($source, '/** Contrat UNIQUE offert aux actions du menu. */');
                self::assertNotFalse($debut);
                self::assertNotFalse($fin);
                $source = substr($source, $debut, $fin - $debut);
            }
            // (Le rabat d'un SOUS-menu vers la gauche reste à l'écran des listes : c'est une
            // classe, pas un placement.)
            if (preg_match("/'ArrowUp'|'Home'|'End'|'Escape'|\\.style\\.(?:left|top)\\s*=|offsetWidth|addEventListener\\('(?:scroll|pointerdown|resize)'|positionnerMenu\\(/", $source, $m)) {
                $fautifs[] = "$fichier : {$m[0]}";
            }
        }
        self::assertSame([], $fautifs, 'Placement et clavier : la coquille (menu-contextuel.js).');
    }

    /**
     * Un raccourci à une lettre ne joue pas une entrée inactive (le « A » d'« Ajouter » quand la
     * création est interdite) — comme Entrée dans la coquille.
     */
    public function testUnRaccourciNeJouePasUneEntreeInactive(): void
    {
        self::assertMatchesRegularExpression("/targetButton\\.getAttribute\\('aria-disabled'\\) === 'true'/", self::source('context-menu_controller.js'));
    }

    /**
     * Le menu passe AU-DESSUS de l'en-tête collant d'une rubrique (.sticky-header, barre d'outils
     * comprise) : un menu qui remonte au-dessus de sa ligne y passait dessous (Serge, 2026-10-25).
     */
    public function testLeMenuPasseAuDessusDeLEnTeteDeLaRubrique(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2) . '/assets/styles/app.css');
        self::assertSame(1, preg_match('/\n\.sticky-header \{[^}]*z-index: (\d+);/', $css, $entete));
        self::assertSame(1, preg_match('/\n\.context-menu \{[^}]*z-index: (\d+);/', $css, $menu));
        self::assertGreaterThan((int) $entete[1], (int) $menu[1]);
        self::assertLessThan(1055, (int) $menu[1], 'Sous les modales Bootstrap (1055).');
    }

    /**
     * Un menu par rubrique ouverte : seul celui de la rubrique concernée répond au contexte —
     * sinon le menu d'un onglet caché fermait le bon (coquille : un seul menu ouvert).
     */
    public function testChaqueMenuDesListesNEcouteQueSaRubrique(): void
    {
        // Garde de lecture (le comportement est tenu par l'E2E) : la rubrique du menu est comparée
        // à celle du contexte, et un menu d'une autre rubrique se FERME (ses raccourcis à une
        // lettre ne doivent pas survivre dans l'onglet suivant).
        $source = self::source('context-menu_controller.js');
        self::assertStringContainsString("closest('[data-tab-id]')", $source);
        self::assertMatchesRegularExpression('/workspaceTabId !== onglet\)\s*\{\s*this\.hideContextMenu\(\);\s*return;/', $source);
    }

    /** L'ancien « un seul menu SOA ouvert » par événement : la coquille le garantit pour tous. */
    public function testPlusDEvenementMaisonPourUnSeulMenuOuvert(): void
    {
        self::assertStringNotContainsString('soa:context-menu.open', self::source('soa-context-menu_controller.js'));
        self::assertStringNotContainsString('keydown->assistant-chat#naviguerMenu"', (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/components/_assistant_ia_chat.html.twig'));
    }
}
