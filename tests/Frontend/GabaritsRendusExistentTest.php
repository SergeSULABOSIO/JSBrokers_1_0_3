<?php

namespace App\Tests\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * TOUT GABARIT QUE LE CODE REND EXISTE (chantier 7).
 *
 * Un `render('…html.twig')` vers un fichier absent ne se voit pas à la lecture : la route
 * répond 500 à qui la trouve. Trois portes l'avaient fait (`/admin/piste/test`,
 * `/admin/article/test`, `/admin/demande_contact`). Ce test lit chaque nom de gabarit écrit en
 * toutes lettres dans `src/` et exige son fichier.
 */
class GabaritsRendusExistentTest extends TestCase
{
    private const RACINE = __DIR__ . '/../../';

    public function testChaqueGabaritRenduExiste(): void
    {
        $absents = [];
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RACINE . 'src', \FilesystemIterator::SKIP_DOTS));
        foreach ($fichiers as $fichier) {
            if ($fichier->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($fichier->getPathname());
            // Pages (render, renderView) et e-mails (htmlTemplate). Un gabarit passé par une
            // variable échappe à cette lecture : seuls les noms écrits en toutes lettres comptent.
            if (preg_match_all('/->(?:render|renderView|htmlTemplate)\(\s*[\'"]([^\'"]+\.twig)[\'"]/', $code, $trouves) < 1) {
                continue;
            }
            foreach ($trouves[1] as $gabarit) {
                if (!is_file(self::RACINE . 'templates/' . $gabarit)) {
                    $absents[] = $gabarit . ' (' . $fichier->getFilename() . ')';
                }
            }
        }

        self::assertSame([], array_values(array_unique($absents)), 'Gabarit rendu mais absent : la route répondrait 500.');
    }
}
