<?php

namespace App\Tests\Deploiement;

use PHPUnit\Framework\TestCase;

/**
 * Tout gabarit nommé dans le code doit exister SOUS CE NOM EXACT, casse comprise.
 *
 * ── L'INCIDENT DU 2026-09-13, LE SECOND ─────────────────────────────────────
 * Le site venait d'être mis en ligne. La création d'un assureur s'enregistrait
 * bien, mais la collection « Documents » du dialogue répondait 500, et le
 * navigateur n'en disait rien de plus. Le journal de production, lui, nommait la
 * cause :
 *
 *   Unable to find template "components/_list_manager.html.twig"
 *
 * Le fichier existait pourtant — sous le nom « _List_manager.html.twig », avec
 * un L majuscule. Le code en demandait un autre, d'une seule lettre. Windows ne
 * distingue pas les deux ; Linux ne voit que ça.
 *
 * ── POURQUOI CasseDesFichiersTest NE L'A PAS VU ─────────────────────────────
 * Ce test-là compare le nom enregistré par git au nom présent sur le disque. Ici
 * les deux CONCORDAIENT : c'est la CHAÎNE ÉCRITE DANS LE CODE qui divergeait.
 * Un troisième lieu, donc, qu'aucune des deux vérifications ne regardait — et un
 * commentaire du code annonçait même le renommage qui n'avait jamais eu lieu.
 *
 * ── CE QUI EST VÉRIFIÉ, ET CE QUI NE L'EST PAS ──────────────────────────────
 * On ne signale une référence que si un fichier lui correspond À LA CASSE PRÈS.
 * Une référence introuvable même en ignorant la casse désigne un gabarit de
 * bundle ou d'espace de noms (@assetsvendor, bootstrap_5_layout…), qui ne vit
 * pas sous templates/ : elle est ignorée, sans quoi le test crierait au loup à
 * chaque gabarit hérité de Symfony.
 */
final class ReferencesDeGabaritsTest extends TestCase
{
    private static function racine(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * Tous les fichiers d'un dossier, en chemins relatifs à templates/.
     *
     * @return array<string, string> nom en minuscules => nom réel
     */
    private static function gabaritsExistants(): array
    {
        $racine = self::racine() . '/templates';
        $trouves = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $fichier) {
            if (!$fichier->isFile() || !str_ends_with($fichier->getFilename(), '.twig')) {
                continue;
            }

            $relatif = str_replace('\\', '/', substr($fichier->getPathname(), \strlen($racine) + 1));
            $trouves[mb_strtolower($relatif)] = $relatif;
        }

        return $trouves;
    }

    /**
     * Les gabarits nommés dans le code PHP et dans les autres gabarits.
     *
     * @return array<string, list<string>> référence => fichiers qui la nomment
     */
    private static function referencesTrouvees(): array
    {
        $references = [];

        foreach (['src', 'templates'] as $dossier) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::racine() . '/' . $dossier, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $fichier) {
                if (!$fichier->isFile()) {
                    continue;
                }
                if (!\in_array($fichier->getExtension(), ['php', 'twig'], true)) {
                    continue;
                }

                $source = (string) file_get_contents($fichier->getPathname());

                // Toute chaîne littérale qui ressemble à un chemin de gabarit.
                // Les noms construits dynamiquement (concaténation, variable)
                // ne peuvent pas être vérifiés ici, et ne le sont pas.
                if (!preg_match_all('#[\'"]([A-Za-z0-9_@/.-]+\.twig)[\'"]#', $source, $trouves)) {
                    continue;
                }

                $chemin = str_replace('\\', '/', substr($fichier->getPathname(), \strlen(self::racine()) + 1));

                foreach ($trouves[1] as $reference) {
                    // Les espaces de noms Twig (@images, @assetsvendor…) ne
                    // pointent pas sous templates/ : ils sont hors sujet.
                    if (str_starts_with($reference, '@')) {
                        continue;
                    }
                    $references[$reference][] = $chemin;
                }
            }
        }

        return $references;
    }

    public function testChaqueGabaritNommeExisteAvecLaBonneCasse(): void
    {
        $existants = self::gabaritsExistants();
        $fautes = [];

        foreach (self::referencesTrouvees() as $reference => $fichiers) {
            // Existe tel quel : rien à dire.
            if (\in_array($reference, $existants, true)) {
                continue;
            }

            // N'existe même pas en ignorant la casse : gabarit de bundle ou
            // d'espace de noms, hors de notre responsabilité.
            $minuscules = mb_strtolower($reference);
            if (!isset($existants[$minuscules])) {
                continue;
            }

            // Existe, mais sous une AUTRE casse. C'est exactement le défaut qui
            // a mis la production en panne : invisible sous Windows, fatal ailleurs.
            $fautes[] = sprintf(
                "  %s\n    demandé par : %s\n    fichier réel : %s",
                $reference,
                implode(', ', array_unique($fichiers)),
                $existants[$minuscules]
            );
        }

        self::assertSame(
            [],
            $fautes,
            "Des gabarits sont nommés avec une casse qui ne correspond pas au fichier.\n"
            . "Sous Windows les deux désignent le même fichier ; sur un serveur Linux, le\n"
            . "rendu échoue et la page répond 500.\n"
            . "Corriger par un renommage en deux temps :\n"
            . "  git mv <ancien> <temporaire> && git mv <temporaire> <nouveau>\n\n"
            . implode("\n", $fautes)
        );
    }
}
