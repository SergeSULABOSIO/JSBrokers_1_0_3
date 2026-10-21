<?php

namespace App\Tests\Security;

use App\Twig\Extension\ChampRechercheExtension;
use PHPUnit\Framework\TestCase;

/**
 * TOUT CHAMP DE RECHERCHE OU DE FILTRE PORTE SES PROTECTIONS (chantier 16).
 *
 * 2026-10-20 : Chrome/Edge collaient l'adresse e-mail de l'utilisateur dans les recherches du
 * tableau de bord, qui filtraient alors la liste à son insu. Inventaire avant correction : 29
 * champs (28 gabarits, 1 en JavaScript), aucun entièrement protégé. Ce test refuse tout champ
 * de recherche — présent ou futur — qui ne passe pas par la source unique :
 *   gabarit      → {{ attributs_champ_recherche(…) }} (ChampRechercheExtension)
 *   JavaScript   → attributsChampRecherche(…) (assets/controllers/champ-recherche.js)
 */
class ChampsDeRechercheProtegesTest extends TestCase
{
    private const RACINE = __DIR__ . '/../..';

    /** Un champ « de recherche » : type search, ou type text dont un indice dit recherche/filtre. */
    private const INDICE = '/search|recherch|filtr|cherch|query|terme|keyword|simpleSearch/i';

    /** @return list<array{string, string}> [emplacement, balise] des champs de recherche */
    private static function champs(): array
    {
        $champs = [];
        foreach (['templates', 'assets'] as $dossier) {
            $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RACINE . '/' . $dossier, \FilesystemIterator::SKIP_DOTS));
            foreach ($fichiers as $fichier) {
                $chemin = str_replace('\\', '/', $fichier->getPathname());
                if (str_contains($chemin, '/assets/vendor/') || !preg_match('/\.(twig|js)$/', $chemin) || preg_match('#/templates/emails/|/pdf/#', $chemin)) {
                    continue;
                }
                $contenu = (string) file_get_contents($chemin);
                // Commentaires Twig et JS blanchis : citer « <input type="search"> » n'est pas en poser un.
                $contenu = (string) preg_replace_callback('/\{#.*?#\}|\/\*.*?\*\/|(?<![:"\'])\/\/[^\n]*/s', static fn (array $c): string => str_repeat("\n", substr_count($c[0], "\n")), $contenu);
                preg_match_all('/<input\b(?:[^>"\'`]|"[^"]*"|\'[^\']*\'|`[^`]*`)*>/is', $contenu, $m, \PREG_OFFSET_CAPTURE);
                foreach ($m[0] as [$balise, $position]) {
                    $type = preg_match('/\stype\s*=\s*["\']?([a-z]+)/i', $balise, $t) ? strtolower($t[1]) : 'text';
                    if ('search' === $type || ('text' === $type && preg_match(self::INDICE, $balise))) {
                        $champs[] = [substr($chemin, \strlen(self::RACINE) + 1) . ':' . (substr_count(substr($contenu, 0, $position), "\n") + 1), $balise];
                    }
                }
            }
        }

        return $champs;
    }

    public function testChaqueChampDeRecherchePasseParLaSourceUnique(): void
    {
        $champs = self::champs();
        self::assertGreaterThanOrEqual(29, \count($champs), 'L\'inventaire a perdu des champs : la détection est cassée.');

        $nus = [];
        foreach ($champs as [$ou, $balise]) {
            if (!str_contains($balise, 'attributs_champ_recherche(') && !str_contains($balise, 'attributsChampRecherche(')) {
                $nus[] = $ou;
            }
        }

        self::assertSame([], $nus, 'Champ de recherche sans protections : {{ attributs_champ_recherche(…) }} (ou attributsChampRecherche(…) en JS).');
    }

    /**
     * Un champ d'un formulaire de recherche ENVOYÉ (`<form method="get">`) n'est jamais détaché :
     * avec `form="jsb-hors-formulaire"`, il cesserait d'être envoyé et la recherche ne filtrerait
     * plus rien — en silence. Il prend les protections seules : `attributs_champ_recherche()`.
     */
    public function testUnChampDUnFormulaireGetNEstJamaisDetache(): void
    {
        $detaches = [];
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RACINE . '/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($fichiers as $fichier) {
            $chemin = str_replace('\\', '/', $fichier->getPathname());
            if (!str_ends_with($chemin, '.twig')) {
                continue;
            }
            $contenu = (string) preg_replace_callback('/\{#.*?#\}/s', static fn (array $c): string => str_repeat("\n", substr_count($c[0], "\n")), (string) file_get_contents($chemin));
            preg_match_all("/attributs_champ_recherche\\(\\s*'[^']+'\\s*\\)/", $contenu, $m, \PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [, $position]) {
                $avant = substr($contenu, 0, $position);
                $ouvre = strrpos($avant, '<form');
                $ferme = strrpos($avant, '</form>');
                if (false !== $ouvre && (false === $ferme || $ferme < $ouvre) && preg_match('/^<form[^>]*method="get"/i', substr($contenu, $ouvre, 300))) {
                    $detaches[] = substr($chemin, strpos($chemin, 'templates/')) . ':' . (substr_count($avant, "\n") + 1);
                }
            }
        }

        self::assertSame([], $detaches, 'Champ d\'un <form method="get"> détaché : il ne serait plus envoyé. Utiliser attributs_champ_recherche() sans nom.');
    }

    /** Un champ de recherche n'est jamais de type « text » : type search (indice pour les remplisseurs). */
    public function testAucunChampDeRechercheDeTypeTexte(): void
    {
        $texte = array_column(array_filter(self::champs(), static fn (array $c): bool => (bool) preg_match('/\stype\s*=\s*["\']?text\b/i', $c[1])), 0);

        self::assertSame([], $texte);
    }

    public function testLesProtectionsSontCompletes(): void
    {
        foreach (['autocomplete="off"', 'data-lpignore="true"', 'data-1p-ignore', 'data-bwignore', 'data-form-type="other"', 'data-champ-recherche'] as $attendu) {
            self::assertStringContainsString($attendu, ChampRechercheExtension::PROTECTIONS);
        }
        // Hors formulaire : nom neutre ET attaché à un formulaire inexistant (jamais envoyé avec un dialogue).
        self::assertSame('name="jsb-filtre" form="jsb-hors-formulaire" ' . ChampRechercheExtension::PROTECTIONS, ChampRechercheExtension::attributs('jsb-filtre'));
        self::assertSame(ChampRechercheExtension::PROTECTIONS, ChampRechercheExtension::attributs());
    }

    /** La jumelle JavaScript dit exactement la même chose que l'extension Twig. */
    public function testLaJumelleJavaScriptEstIdentique(): void
    {
        $js = (string) file_get_contents(self::RACINE . '/assets/controllers/champ-recherche.js');

        self::assertStringContainsString("PROTECTIONS_CHAMP_RECHERCHE = '" . ChampRechercheExtension::PROTECTIONS . "'", $js);
        self::assertStringContainsString("FORMULAIRE_INEXISTANT = '" . ChampRechercheExtension::FORMULAIRE_INEXISTANT . "'", $js);
    }

    /** Aucun élément ne porte l'identifiant du formulaire inexistant : sinon les champs y seraient rattachés. */
    public function testLeFormulaireInexistantNExistePas(): void
    {
        $occurrences = [];
        foreach (['templates', 'assets'] as $dossier) {
            $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RACINE . '/' . $dossier, \FilesystemIterator::SKIP_DOTS));
            foreach ($fichiers as $fichier) {
                $chemin = str_replace('\\', '/', $fichier->getPathname());
                if (!str_contains($chemin, '/assets/vendor/') && preg_match('/\.(twig|js)$/', $chemin)
                    && preg_match('/\bid\s*=\s*["\']' . ChampRechercheExtension::FORMULAIRE_INEXISTANT . '["\']/', (string) file_get_contents($chemin))) {
                    $occurrences[] = $chemin;
                }
            }
        }

        self::assertSame([], $occurrences);
    }
}
