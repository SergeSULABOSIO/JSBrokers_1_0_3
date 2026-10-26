<?php

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * DES PORTES QUI NE MÈNENT NULLE PART N'EXISTENT PLUS (chantier 7, plan validé par Serge le
 * 2026-10-26).
 *
 * Trois routes répondaient 500, leur gabarit n'existant pas, et rien ne pointait vers elles :
 *  - `/admin/piste/test` et `/admin/article/test` : des éditeurs d'essai jamais livrés ;
 *  - `/admin/demande_contact` : une seconde porte du formulaire de contact. La seule vraie est la
 *    vitrine (SecurityController) ; celle-ci, sans formulaire qui y envoie, n'était qu'une
 *    surface d'attaque de plus.
 */
class RoutesMortesTest extends KernelTestCase
{
    /** @return iterable<string, array{string}> */
    public static function chemins(): iterable
    {
        yield 'éditeur de piste' => ['/admin/piste/test'];
        yield "éditeur d'article" => ['/admin/article/test'];
        yield 'seconde porte du contact' => ['/admin/demande_contact'];
    }

    /** @dataProvider chemins */
    public function testLaRouteNExistePlus(string $chemin): void
    {
        self::bootKernel();
        $routes = static::getContainer()->get(RouterInterface::class)->getRouteCollection();

        foreach ($routes as $nom => $route) {
            self::assertNotSame($chemin, $route->getPath(), sprintf('La route « %s » (%s) devait disparaître.', $nom, $chemin));
        }
    }
}
