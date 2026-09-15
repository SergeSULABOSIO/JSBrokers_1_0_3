<?php

namespace App\Tests\Twig;

use App\Services\Canvas\Provider\Icon\IconCanvasProvider;
use PHPUnit\Framework\TestCase;

/**
 * TOUTE ICÔNE INVOQUÉE PAR UN ÉCRAN EXISTE SUR LE DISQUE.
 *
 * ── LA FAILLE QUE CE TEST FERME ─────────────────────────────────────────────
 * `IconAliasVendorisationTest` vérifie que chaque alias DÉCLARÉ dans
 * IconCanvasProvider pointe vers un SVG vendorisé. C'est nécessaire, et ce
 * n'est pas suffisant : il ne regarde jamais ce que les gabarits INVOQUENT.
 *
 * Deux pannes passaient donc au travers, et les deux se sont produites :
 *
 * 1. L'ALIAS QUI N'EXISTE PAS. Le 2026-09-15, la rubrique de supervision a
 *    répondu 500 en production sur `icone: 'action:valider'` — un alias absent
 *    de la table. `resolve_icon_name()` laisse passer tel quel ce qu'il ne
 *    connaît pas, et le nom part vers Iconify, qui n'a évidemment aucune icône
 *    de ce nom. Rien, dans toute la suite de tests, ne regardait ce nom.
 *
 * 2. LE NOM ICONIFY BRUT NON VENDORISÉ. `ux_icon('mdi:arrow-left')` contourne
 *    entièrement la table d'alias. Le projet n'ayant pas de
 *    config/packages/ux_icons.yaml, une icône absente du disque déclenche un
 *    TÉLÉCHARGEMENT AU MOMENT DU RENDU : lent en développement, et 500 en
 *    production dès que le réseau sortant manque ou qu'iconify.design tousse.
 *    Au 2026-09-15, 48 invocations étaient dans ce cas.
 *
 * ── LA RÈGLE, ET COMMENT LA SATISFAIRE ──────────────────────────────────────
 * Peu importe la forme employée — alias maison ou nom direct : ce qui est
 * invoqué doit exister dans assets/icons/{jeu}/{nom}.svg. Quand ce test tombe :
 *
 *     php bin/console ux:icons:lock        (scanne le projet et importe)
 *
 * puis versionner les SVG obtenus. Si le nom est un alias mal saisi, c'est le
 * NOM qu'il faut corriger — l'import ne le sauvera pas.
 *
 * ── CE QU'IL NE PEUT PAS VOIR ───────────────────────────────────────────────
 * Les noms calculés (`resolve_icon_name(entite.icone)`) échappent à toute
 * lecture statique. Ce test couvre les littéraux, qui sont l'écrasante
 * majorité — et la totalité des cas qui ont cassé jusqu'ici.
 */
final class IconInvocationsVendoriseesTest extends TestCase
{
    private const RACINE_ICONES = __DIR__ . '/../../assets/icons';

    /** Les dossiers dont on lit les écrans. */
    private const SOURCES = [__DIR__ . '/../../templates', __DIR__ . '/../../src'];

    /**
     * Les trois formes par lesquelles un nom d'icône littéral entre dans le
     * rendu. La troisième est la syntaxe composant de ux-icons.
     */
    private const FORMES = [
        "/resolve_icon_name\('([^']+)'\)/",
        "/ux_icon\('([^']+)'/",
        '/<twig:ux:icon\s+name="([^"]+)"/',
    ];

    /**
     * @return array<string, string> alias => nom d'icône résolu
     */
    private function tableDesAlias(): array
    {
        $constante = (new \ReflectionClass(IconCanvasProvider::class))->getReflectionConstant('ICON_ALIAS_MAP');
        self::assertNotFalse($constante, 'La constante ICON_ALIAS_MAP a disparu de IconCanvasProvider.');

        /** @var array<string, string> $table */
        $table = $constante->getValue();

        return $table;
    }

    /**
     * Tous les noms littéraux invoqués, avec le fichier qui les invoque.
     *
     * @return array<string, list<string>> nom => fichiers
     */
    private function invocations(): array
    {
        $trouves = [];

        foreach (self::SOURCES as $racine) {
            if (!is_dir($racine)) {
                continue;
            }

            $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));

            foreach ($fichiers as $fichier) {
                if (!$fichier->isFile() || !in_array($fichier->getExtension(), ['twig', 'php'], true)) {
                    continue;
                }

                $contenu = (string) file_get_contents($fichier->getPathname());

                foreach (self::FORMES as $forme) {
                    if (!preg_match_all($forme, $contenu, $correspondances)) {
                        continue;
                    }

                    foreach ($correspondances[1] as $nom) {
                        // Un attribut `name="{{ … }}"` ne porte pas un nom, il
                        // porte une EXPRESSION évaluée au rendu — souvent un
                        // appel à resolve_icon_name(), dont le littéral est de
                        // toute façon déjà capté par la première forme. Le lire
                        // comme un nom d'icône ferait échouer ce test sur du
                        // code parfaitement sain.
                        if (str_contains($nom, '{{') || str_contains($nom, '{%')) {
                            continue;
                        }

                        $trouves[$nom][] = basename($fichier->getPathname());
                    }
                }
            }
        }

        return $trouves;
    }

    public function testChaqueIconeInvoqueeExisteSurLeDisque(): void
    {
        $alias = $this->tableDesAlias();
        $manquantes = [];

        foreach ($this->invocations() as $nom => $fichiers) {
            // Un alias maison est d'abord traduit ; tout le reste est pris tel
            // quel — exactement ce que fait resolve_icon_name().
            $resolu = $alias[$nom] ?? $nom;

            if (!preg_match('/^([a-z0-9-]+):([a-z0-9-]+)$/', $resolu, $parties)) {
                $manquantes[] = sprintf('%s (nom invalide) — vu dans %s', $nom, implode(', ', array_unique($fichiers)));
                continue;
            }

            if (!is_file(self::RACINE_ICONES . '/' . $parties[1] . '/' . $parties[2] . '.svg')) {
                $manquantes[] = sprintf(
                    '%s%s — vu dans %s',
                    $nom,
                    $resolu === $nom ? '' : ' => ' . $resolu,
                    implode(', ', array_unique($fichiers))
                );
            }
        }

        sort($manquantes);

        self::assertSame(
            [],
            $manquantes,
            "Ces icônes sont invoquées par un écran mais n'existent pas dans assets/icons/.\n"
            . "Chacune provoque un téléchargement au rendu — donc un 500 en production sans réseau sortant —\n"
            . "ou une erreur immédiate si le nom n'existe pas du tout chez Iconify.\n"
            . "Remède : php bin/console ux:icons:lock, puis versionner les SVG obtenus.\n\n"
            . implode("\n", $manquantes)
        );
    }
}
