<?php

namespace App\Tests\Workspace;

use PHPUnit\Framework\TestCase;

/**
 * UN NOM PARTAGÉ QU'ON UTILISE SANS L'IMPORTER CASSE TOUT — EN SILENCE.
 *
 * C'est arrivé deux fois de suite, et de la même façon : une règle extraite en module
 * partagé, appelée depuis un contrôleur qui n'en portait pas l'`import`. À l'exécution,
 * l'identifiant vaut `undefined`, la méthode lève dès le premier tour de boucle — et TOUT ce
 * qu'elle devait produire disparaît d'un coup :
 *
 *   — `conditionRemplie` manquant dans `dialog-instance_controller` : plus aucune action
 *     conditionnelle dans la barre d'un dialogue ouvert ;
 *   — `etatChipPreset` manquant dans `list-manager_controller` : plus AUCUN chip de filtre
 *     ne se marquait actif, sur toutes les rubriques à la fois.
 *
 * Rien ne l'attrapait : `node --check` ne valide que la syntaxe, et ces contrôleurs n'ont pas
 * de test unitaire — ils dépendent de Stimulus et d'un DOM que cette suite n'a pas. D'où
 * cette vérification STATIQUE : elle lit les modules partagés, relève ce qu'ils exportent, et
 * exige que tout fichier qui s'en sert l'importe.
 *
 * ⚠ UN GARDE-FOU QUI CRIE AU LOUP FINIT DÉSACTIVÉ. Quatre méprises guettent, et les quatre
 * se sont produites à l'écriture de ce test — d'où la sévérité de ce qui compte pour un
 * USAGE :
 *
 *   — un fichier définit SON PROPRE `ajouter()` (méthode de classe) sans rien devoir au
 *     module qui exporte ce nom → `definitSonPropreNom()` ;
 *   — un export peut être une CONSTANTE (`MARGE_VIEWPORT`), utilisée sans parenthèses : la
 *     chercher sous forme d'appel la déclarait inutilisée à tort ;
 *   — la prose et les noms d'événements emploient les mêmes mots (« Impossible de retirer le
 *     client », `ui:client.retirer-portefeuille`) → les chaînes sont retirées, et un mot à
 *     trait d'union n'est pas notre identifiant ;
 *   — l'import DYNAMIQUE (`await import('./x.js')`) branche tout autant que le statique.
 */
class ImportsPartagesTest extends TestCase
{
    private const DOSSIER = __DIR__ . '/../../assets/controllers';

    /**
     * Les modules partagés : les fichiers d'`assets/controllers` qui ne sont PAS des
     * contrôleurs Stimulus et qui exportent des noms.
     *
     * @return array<string, string[]> nom du module => noms exportés
     */
    private function modulesPartages(): array
    {
        $modules = [];

        foreach (glob(self::DOSSIER . '/*.js') as $chemin) {
            $nom = basename($chemin);
            if (str_ends_with($nom, '_controller.js')) {
                continue;
            }

            $source = (string) file_get_contents($chemin);
            preg_match_all('/^export (?:async function|function|class|const) ([A-Za-z_$][\w$]*)/m', $source, $m);
            if ($m[1] !== []) {
                $modules[$nom] = $m[1];
            }
        }

        return $modules;
    }

    /**
     * Le CODE seul : ni commentaires, ni chaînes.
     *
     * Les deux emploient les mêmes mots sans rien consommer.
     */
    private function codeNu(string $source): string
    {
        $sansCommentaires = preg_replace('!/\*.*?\*/|//[^\n]*!s', '', $source) ?? $source;
        $sansChaines = preg_replace('/\'[^\'\\\\\n]*\'|"[^"\\\\\n]*"|`[^`]*`/s', "''", $sansCommentaires);

        return $sansChaines ?? $sansCommentaires;
    }

    /**
     * L'identifiant apparaît-il NU — ni `obj.nom`, ni `nom:` de propriété, ni fragment d'un
     * mot à trait d'union ?
     *
     * C'est la seule forme qui consomme réellement un import, et celle qui lève quand
     * l'import manque.
     */
    private function utiliseLIdentifiant(string $code, string $nom): bool
    {
        return 1 === preg_match('/(?<![-.\w$])' . preg_quote($nom, '/') . '\b(?![-\w$]|\s*:)/', $code);
    }

    /**
     * Le fichier définit-il lui-même ce nom ? Alors il ne doit rien au module.
     *
     * Couvre la déclaration (`function`, `const`, `class`) et la méthode de classe en forme
     * abrégée (`ajouter(nouveaux) {`), qui est le cas qui m'a piégé.
     */
    private function definitSonPropreNom(string $code, string $nom): bool
    {
        $n = preg_quote($nom, '/');

        return 1 === preg_match('/\b(?:function|const|let|var|class)\s+' . $n . '\b/', $code)
            || 1 === preg_match('/^\s*(?:async\s+|static\s+|get\s+|set\s+)*' . $n . '\s*\([^)]*\)\s*\{/m', $code);
    }

    /** Statique ou dynamique : les deux branchent le module. */
    private function importe(string $source, string $module): bool
    {
        return str_contains($source, "from './" . $module . "'")
            || str_contains($source, "import('./" . $module . "')");
    }

    /** Le garde-fou a besoin de matière : sans modules, il ne prouverait rien. */
    public function testDesModulesPartagesExistent(): void
    {
        $modules = $this->modulesPartages();

        self::assertNotSame([], $modules, 'Aucun module partagé trouvé : le chemin a dû changer.');
        self::assertArrayHasKey('condition-action.js', $modules);
        self::assertArrayHasKey('chip-preset-etat.js', $modules);
        self::assertContains('etatChipPreset', $modules['chip-preset-etat.js']);
    }

    /**
     * TOUT FICHIER QUI SE SERT D'UN NOM PARTAGÉ DOIT L'IMPORTER.
     *
     * C'est le test qui aurait épargné les deux pannes ci-dessus.
     */
    public function testChaqueUsageDUnNomPartageEstImporte(): void
    {
        $modules = $this->modulesPartages();
        $manquants = [];

        foreach (glob(self::DOSSIER . '/*.js') as $chemin) {
            $nomFichier = basename($chemin);
            $source = (string) file_get_contents($chemin);
            $code = $this->codeNu($source);

            foreach ($modules as $module => $exports) {
                if ($module === $nomFichier || $this->importe($source, $module)) {
                    continue; // un module ne s'importe pas lui-même
                }

                foreach ($exports as $export) {
                    if ($this->utiliseLIdentifiant($code, $export) && !$this->definitSonPropreNom($code, $export)) {
                        $manquants[] = sprintf('%s se sert de %s sans importer ./%s', $nomFichier, $export, $module);
                    }
                }
            }
        }

        self::assertSame([], $manquants, sprintf(
            "Des noms partagés sont utilisés sans import — ils vaudront `undefined` et la méthode "
            . "lèvera au premier appel, faisant disparaître TOUT ce qu'elle produit :\n  - %s",
            implode("\n  - ", $manquants),
        ));
    }

    /**
     * Et l'inverse : un import qui ne sert plus.
     *
     * Rien ne casse, mais c'est la trace d'une extraction à moitié défaite — et le prochain
     * lecteur croira la règle encore consommée ici.
     */
    public function testAucunImportPartageNEstInutilise(): void
    {
        $modules = $this->modulesPartages();
        $inutiles = [];

        foreach (glob(self::DOSSIER . '/*.js') as $chemin) {
            $nomFichier = basename($chemin);
            $source = (string) file_get_contents($chemin);
            // On retire la ligne d'import elle-même : elle NOMME l'identifiant sans le consommer.
            $code = $this->codeNu(preg_replace('/^import [^;]+;$/m', '', $source) ?? $source);

            foreach ($modules as $module => $exports) {
                if ($module === $nomFichier || !str_contains($source, "from './" . $module . "'")) {
                    continue; // seul l'import STATIQUE peut être orphelin
                }

                $utilise = false;
                foreach ($exports as $export) {
                    if ($this->utiliseLIdentifiant($code, $export)) {
                        $utilise = true;
                        break;
                    }
                }
                if (!$utilise) {
                    $inutiles[] = sprintf('%s importe ./%s sans s’en servir', $nomFichier, $module);
                }
            }
        }

        self::assertSame([], $inutiles, implode("\n  - ", $inutiles));
    }
}
