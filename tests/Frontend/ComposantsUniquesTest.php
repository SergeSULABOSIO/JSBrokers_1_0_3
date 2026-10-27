<?php

namespace App\Tests\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * LE GARDE-FOU DU CHANTIER 3 : une infobulle, une coquille de menu — et pas une de plus.
 *
 * - UNE INFOBULLE : seul `infobulle-moteur.js` fabrique une bulle (rôle `tooltip`, classe
 *   `.jsb-ctx-tip`) ; aucune infobulle Bootstrap. Un écran DÉCLARE son contenu
 *   (`data-infobulle="{{ sections|json_encode|e('html_attr') }}"`) ou pose un `title` dans une
 *   barre d'actions ; une bulle posée par le code passe par `afficherInfobulle()`.
 * - UNE COQUILLE DE MENU : tout code qui écoute le clic droit (`contextmenu`) ouvre son menu par
 *   `menu-contextuel.js` — ou n'est qu'un RELAIS déclaré ici, avec son motif, vers un code qui
 *   le fait. Un nouveau menu (`role="menu"`) se déclare dans la liste ci-dessous une fois branché
 *   sur la coquille (ou motivé s'il n'est pas contextuel).
 *
 * Hors d'atteinte d'un test statique, donc à la revue : une bulle « maison » (un élément posé en
 * `position: fixed` au survol) — elle n'a ni rôle ni classe à reconnaître.
 * Garde-fous voisins, plus fins : InfobulleUniqueTest (anciennes bulles disparues),
 * MenuContextuelUniqueTest (les écrans migrés ne réécrivent ni placement ni clavier),
 * MenusDuTableauDeBordTest (les 8 menus du tableau de bord, rôles, focus).
 */
class ComposantsUniquesTest extends TestCase
{
    /** Les relais du clic droit : ils ne montrent rien, ils passent le geste à un code qui ouvre par la coquille. */
    private const RELAIS_DU_CLIC_DROIT = [
        'assets/controllers/tableau-de-bord_controller.js' => 'appelle les fonctions db…CtxOpen d’assets/app.js (ouvrirMenuDuTableauDeBord)',
        'assets/controllers/vue-cartes_controller.js' => 'renvoie le geste de la carte à sa ligne de liste (list-row → context-menu)',
        'assets/controllers/list-row_controller.js' => 'transmet la ligne et le geste (gesteDOuverture) au menu des listes, via le cerveau',
        'assets/controllers/menu-flottant.js' => 'logique pure de la coquille (aucun DOM)',
    ];

    /** Les menus du site : contextuels (coquille), ou menus-boutons motivés. */
    private const MENUS = [
        'templates/admin/entreprise/index.html.twig' => 'coquille : cartes de cabinet',
        'templates/admin/soa/soa_client_workspace.html.twig' => 'coquille : relevé de compte',
        'templates/components/_context_menu.html.twig' => 'coquille : listes de l’espace de travail',
        'templates/components/_toolbar.html.twig' => 'coquille : « Plus » du rail d’outils (toolbar_controller.js)',
        'templates/console/base.html.twig' => 'coquille : lignes de la console',
        'templates/components/_assistant_ia_chat.html.twig' => 'coquille : bulles de Ket ; menu-bouton d’entête (se déplie en plein écran)',
        'templates/components/dashboard/_block_bordereaux.html.twig' => 'coquille : tableau de bord',
        'templates/components/dashboard/_block_notes.html.twig' => 'coquille : tableau de bord',
        'templates/components/dashboard/_block_pistes.html.twig' => 'coquille : tableau de bord',
        'templates/components/dashboard/_block_tasks.html.twig' => 'coquille : tableau de bord',
        'templates/components/dashboard/_block_users.html.twig' => 'coquille : tableau de bord',
        'templates/components/dashboard/_depenses.html.twig' => 'coquille : tableau de bord',
        'templates/components/dashboard/_renewals.html.twig' => 'coquille : tableau de bord',
        'templates/components/dashboard/_sinistres.html.twig' => 'coquille : tableau de bord',
        'assets/controllers/barre-actions.js' => 'menu-bouton d’une famille d’actions (barre partagée)',
        'assets/controllers/context-menu_controller.js' => 'sous-menu d’une famille, dans le menu des listes',
    ];

    /** Toute écriture d'un clic droit : écouteur (guillemets quelconques), attribut, action Stimulus, événement relayé. */
    private const CLIC_DROIT = "/addEventListener\\(\\s*['\"`]contextmenu['\"`]|\\boncontextmenu\\b|contextmenu(?:@\\w+)?->|type === ['\"`]contextmenu['\"`]|new MouseEvent\\(\\s*['\"`]contextmenu['\"`]/";

    /** La coquille UTILISÉE (pas seulement importée) : un `new MenuContextuel(`, importé de menu-contextuel.js. */
    private static function ouvreParLaCoquille(string $source): bool
    {
        return (bool) preg_match("#from\\s+['\"](?:\\./|(?:\\.\\./)+)(?:controllers/)?menu-contextuel\\.js['\"]#", $source)
            && str_contains($source, 'new MenuContextuel(');
    }

    private static function racine(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** @return array<string, string> chemin relatif => source, des .js et .twig de assets/ (hors vendor) et templates/ */
    private static function sources(): array
    {
        $sources = [];
        foreach (['assets', 'templates'] as $dossier) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::racine() . "/$dossier", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $chemin = str_replace('\\', '/', substr($f->getPathname(), \strlen(self::racine()) + 1));
                if (str_starts_with($chemin, 'assets/vendor/') || !preg_match('/\.(js|mjs|twig)$/', $chemin)) {
                    continue;
                }
                $sources[$chemin] = (string) file_get_contents($f->getPathname());
            }
        }

        return $sources;
    }

    public function testSeulLeMoteurFabriqueUneInfobulle(): void
    {
        $motif = "/setAttribute\\(\\s*['\"`]role['\"`]\\s*,\\s*['\"`]tooltip['\"`]|\\brole\\s*=\\s*['\"`]?tooltip\\b"
            . "|className\\s*=\\s*['\"`][^'\"`]*\\bjsb-ctx-tip\\b|classList\\.add\\([^)]*['\"`]jsb-ctx-tip['\"`]"
            . "|bootstrap\\.(?:Tooltip|Popover)\\b|new\\s+(?:Tooltip|Popover)\\(|data-(?:bs-)?toggle=['\"](?:tooltip|popover)['\"]/";
        $fautifs = [];
        foreach (self::sources() as $chemin => $source) {
            if ($chemin === 'assets/controllers/infobulle-moteur.js') {
                continue;
            }
            if (preg_match($motif, $source, $m)) {
                $fautifs[] = "$chemin : {$m[0]}";
            }
        }
        self::assertSame([], $fautifs, 'Une infobulle passe par l’infobulle unique (infobulle-moteur.js, data-infobulle).');
    }

    public function testToutClicDroitOuvreParLaCoquille(): void
    {
        $fautifs = [];
        foreach (self::sources() as $chemin => $source) {
            if (!str_starts_with($chemin, 'assets/') || $chemin === 'assets/controllers/menu-contextuel.js') {
                continue;
            }
            if (!preg_match(self::CLIC_DROIT, $source)) {
                continue;
            }
            $parLaCoquille = self::ouvreParLaCoquille($source);
            if (!$parLaCoquille && !isset(self::RELAIS_DU_CLIC_DROIT[$chemin])) {
                $fautifs[] = $chemin;
            }
        }
        self::assertSame([], $fautifs, 'Un clic droit ouvre son menu par menu-contextuel.js (ou se déclare relais, avec son motif).');

        // Les contrôleurs appelés par un clic droit dans les gabarits : la coquille, ou un relais.
        $appeles = [];
        foreach (self::sources() as $chemin => $source) {
            if (str_starts_with($chemin, 'templates/') && preg_match_all('/contextmenu(?:@\w+)?->([\w-]+)#/', $source, $m)) {
                foreach ($m[1] as $controleur) {
                    $appeles[$controleur] = $chemin;
                }
            }
        }
        foreach ($appeles as $controleur => $gabarit) {
            $fichier = 'assets/controllers/' . str_replace('--', '/', $controleur) . '_controller.js';
            $source = self::sources()[$fichier] ?? '';
            self::assertTrue(
                self::ouvreParLaCoquille($source) || isset(self::RELAIS_DU_CLIC_DROIT[$fichier]),
                "$gabarit : contextmenu->$controleur — ce contrôleur n'ouvre pas par la coquille.",
            );
        }
    }

    public function testToutMenuEstDeclare(): void
    {
        $nouveaux = [];
        foreach (self::sources() as $chemin => $source) {
            if (preg_match("/\\brole\\s*=\\s*['\"`]?menu\\b|setAttribute\\(\\s*['\"`]role['\"`]\\s*,\\s*['\"`]menu['\"`]/", $source) && !isset(self::MENUS[$chemin])) {
                $nouveaux[] = $chemin;
            }
        }
        self::assertSame([], $nouveaux, 'Un nouveau menu : branché sur la coquille (menu-contextuel.js) puis déclaré dans ComposantsUniquesTest::MENUS, avec son motif.');
        foreach (array_keys(self::MENUS) as $chemin) {
            self::assertFileExists(self::racine() . '/' . $chemin, 'Un menu déclaré qui n’existe plus : retirez-le de la liste.');
        }
    }
}
