<?php

namespace App\Tests\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * L'ICÔNE D'UNE LIGNE DE LISTE EST LA MASCOTTE DE SON ENTITÉ — et rien d'autre (2026-10-26).
 *
 * `_list_row.html.twig` lit `entityCanvas.parametres.icone`. La clé `texte_principal.icone` des
 * canevas de LISTE n'était lue par personne : 51 canevas la portaient, souvent avec l'ancienne
 * mascotte (« role », « paiement », « depense »…), et trompaient qui lisait le code. Retirée ;
 * ce test empêche d'en remettre. (`icone_taille`, lui, est lu : il reste permis.)
 */
class IconeDeLigneMorteTest extends TestCase
{
    public function testAucunCanevasDeListeNePorteDIconeDeLigne(): void
    {
        $fautifs = [];
        foreach (glob(__DIR__ . '/../../src/Services/Canvas/Provider/List/*.php') as $fichier) {
            $code = (string) file_get_contents($fichier);
            foreach (self::blocsTextePrincipal($code) as $bloc) {
                if (preg_match('/["\']icone["\']\s*=>/', $bloc) === 1) {
                    $fautifs[] = basename($fichier);
                    break;
                }
            }
        }

        self::assertSame([], $fautifs, 'La ligne lit la mascotte du canevas d\'entité : texte_principal.icone est une clé morte.');
    }

    /** @return list<string> le contenu de chaque tableau `texte_principal => [ … ]` */
    public static function blocsTextePrincipal(string $code): array
    {
        $blocs = [];
        $offset = 0;
        while (preg_match('/["\']texte_principal["\']\s*=>\s*\[/', $code, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $debut = $m[0][1] + \strlen($m[0][0]);
            $profondeur = 1;
            $i = $debut;
            while ($i < \strlen($code) && $profondeur > 0) {
                $profondeur += $code[$i] === '[' ? 1 : ($code[$i] === ']' ? -1 : 0);
                ++$i;
            }
            $blocs[] = substr($code, $debut, $i - $debut - 1);
            $offset = $i;
        }

        return $blocs;
    }
}
