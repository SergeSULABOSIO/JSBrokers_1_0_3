<?php

namespace App\Tests\Frontend;

use App\Services\CanvasBuilder;
use App\Services\Canvas\Provider\Icon\IconCanvasProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE RAIL DES CHIPS SE LIT À SES ICÔNES (refonte de la colonne 3, C3).
 *
 * Au rail, un groupe de chips n'est plus qu'un bouton à icône : elle doit le DÉSIGNER. Audit
 * du plan : l'icône d'une valeur ne le peut pas — « Toutes » a la même dans 23 groupes,
 * `action:calendar` a quatre sens. Chaque groupe déclare donc la sienne (`icone`, alias
 * `filtre:*`), choisie par Serge le 2026-10-29, et ce test la garde :
 *   - présente, et dessinée par un SVG du dépôt (aucun appel réseau au rendu) ;
 *   - distincte des autres groupes de la rubrique et des icônes de ses propres options ;
 *   - jamais le dessin d'un autre alias — mascotte, `action:*`, `fichier:*` (comparaison du
 *     SVG RÉSOLU : deux noms peuvent dessiner la même chose).
 * Toute liste qui déclare des chips y passe d'office.
 */
class RailDesChipsDiscernableTest extends KernelTestCase
{
    /** @return array<string, string> alias → nom d'icône */
    private static function alias(): array
    {
        return (new \ReflectionClassConstant(IconCanvasProvider::class, 'ICON_ALIAS_MAP'))->getValue();
    }

    /** L'empreinte du SVG que dessine un alias ou un nom d'icône ; null s'il n'est pas sur le disque. */
    private static function dessin(string $nom): ?string
    {
        $nom = self::alias()[$nom] ?? $nom;
        [$jeu, $icone] = array_pad(explode(':', $nom, 2), 2, '');
        $fichier = __DIR__ . '/../../assets/icons/' . $jeu . '/' . $icone . '.svg';

        return is_file($fichier) ? md5_file($fichier) : null;
    }

    /** @return array<string, list<array<string, mixed>>> rubrique → ses groupes de chips */
    private static function rubriquesAChips(): array
    {
        $canevas = static::getContainer()->get(CanvasBuilder::class);
        $rubriques = [];
        foreach (glob(__DIR__ . '/../../src/Services/Canvas/Provider/List/*ListCanvasProvider.php') as $fichier) {
            if (!str_contains((string) file_get_contents($fichier), 'filtres_predefinis')) {
                continue;
            }
            $nom = basename($fichier, 'ListCanvasProvider.php');
            $entite = class_exists('App\\Entity\\' . $nom) ? 'App\\Entity\\' . $nom : $nom;
            $rubriques[$nom] = $canevas->getListeCanvas($entite)['filtres_predefinis'] ?? [];
        }

        return $rubriques;
    }

    public function testChaqueGroupeDeChipsADeclareUneIconeQuiLeDesigne(): void
    {
        self::bootKernel();
        $autres = [];
        foreach (self::alias() as $alias => $nom) {
            if (!str_starts_with($alias, 'filtre:')) {
                $autres[(string) self::dessin($alias)][] = $alias;
            }
        }

        $rubriques = self::rubriquesAChips();
        self::assertGreaterThanOrEqual(8, count($rubriques), 'Les listes à chips sont toutes lues.');

        foreach ($rubriques as $rubrique => $groupes) {
            $dessinsDesGroupes = [];
            foreach ($groupes as $groupe) {
                $ou = sprintf('%s, groupe « %s »', $rubrique, $groupe['titre'] ?? $groupe['libelle'] ?? $groupe['critere']);
                $icone = (string) ($groupe['icone'] ?? '');
                self::assertStringStartsWith('filtre:', $icone, "$ou : icône du groupe (`icone`, alias filtre:*) absente.");
                $dessin = self::dessin($icone);
                self::assertNotNull($dessin, "$ou : « $icone » n'est dessinée par aucun SVG du dépôt.");

                self::assertArrayNotHasKey($dessin, $autres, sprintf('%s : « %s » dessine déjà %s.', $ou, $icone, implode(', ', $autres[$dessin] ?? [])));
                self::assertNotContains($dessin, $dessinsDesGroupes, "$ou : même dessin qu'un autre groupe de la rubrique.");
                $dessinsDesGroupes[] = $dessin;

                foreach ($groupe['options'] ?? [] as $option) {
                    if (!empty($option['icon'])) {
                        self::assertNotSame($dessin, self::dessin((string) $option['icon']), "$ou : même dessin que l'option « {$option['label']} ».");
                    }
                }
            }
        }
    }
}
