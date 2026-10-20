<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * TOUTE ICÔNE NOMMÉE DANS LE CODE (PHP, JavaScript) EXISTE SUR LE DISQUE.
 *
 * `IconInvocationsVendoriseesTest` lit les GABARITS. Il ne voit pas les noms que le CODE fournit
 * aux gabarits : les canevas de liste et de formulaire (`'icone' => 'lucide:users'`),
 * l'extension Twig des icônes, le contrôleur d'icônes, le JavaScript. Le 2026-10-20, 31 icônes
 * étaient dans ce cas : absentes de assets/icons, elles étaient téléchargées depuis
 * api.iconify.design AU MOMENT DU RENDU — et la page tombait en 500 dès qu'Iconify ne
 * répondait pas (vu sur le poste : « Could not resolve host: api.iconify.design »).
 *
 * Quand ce test tombe : `php bin/console ux:icons:import jeu:nom`, puis versionner le SVG.
 */
final class IconesEcritesDansLeCodeVendoriseesTest extends TestCase
{
    private const RACINE = __DIR__ . '/../..';

    /** Jeux Iconify reconnus : ceux déjà présents sur le disque, et les plus courants. */
    private static function jeux(): array
    {
        $presents = array_map('basename', glob(self::RACINE . '/assets/icons/*', \GLOB_ONLYDIR) ?: []);

        return array_values(array_unique([
            ...array_diff($presents, ['jsb']), // jsb : icônes maison, hors Iconify
            'lucide', 'mdi', 'material-symbols', 'tabler', 'heroicons', 'ph', 'ri', 'bi', 'carbon',
            'solar', 'fluent', 'iconoir', 'hugeicons', 'fa6-solid', 'fa6-regular', 'mingcute', 'ic', 'uil', 'bx', 'bxs',
        ]));
    }

    public function testChaqueIconeNommeeDansLeCodeExisteSurLeDisque(): void
    {
        $motif = '/[\'"`](' . implode('|', array_map(static fn (string $j): string => preg_quote($j, '/'), self::jeux())) . '):([a-z0-9]+(?:-[a-z0-9]+)*)[\'"`]/';
        $absentes = [];
        foreach (['src', 'assets'] as $dossier) {
            $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RACINE . '/' . $dossier, \FilesystemIterator::SKIP_DOTS));
            foreach ($fichiers as $fichier) {
                $chemin = str_replace('\\', '/', $fichier->getPathname());
                if (str_contains($chemin, '/assets/vendor/') || str_contains($chemin, '/assets/icons/') || !preg_match('/\.(php|js)$/', $chemin)) {
                    continue;
                }
                preg_match_all($motif, (string) file_get_contents($chemin), $m, \PREG_SET_ORDER);
                foreach ($m as [, $jeu, $nom]) {
                    if (!is_file(self::RACINE . "/assets/icons/$jeu/$nom.svg")) {
                        $absentes["$jeu:$nom"] = substr($chemin, strrpos($chemin, '/src/') !== false ? strrpos($chemin, '/src/') + 1 : strrpos($chemin, '/assets/') + 1);
                    }
                }
            }
        }
        ksort($absentes);

        self::assertSame([], $absentes, 'Icône téléchargée au rendu (500 si Iconify ne répond pas) : php bin/console ux:icons:import ' . implode(' ', array_keys($absentes)));
    }
}
