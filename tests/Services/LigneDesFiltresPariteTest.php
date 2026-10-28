<?php

namespace App\Tests\Services;

use App\Entity\ReversementRetroAgent;
use App\Entity\Tranche;
use App\Services\CanvasBuilder;
use App\Services\Search\LigneDesFiltres;
use App\Services\Search\ProductionScope;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA LIGNE DES FILTRES ACTIFS — moitié « Ket » de la parité écran ↔ Ket (refonte de la
 * colonne 3, C2+).
 *
 * L'écran écrit la ligne en JavaScript (`search-bar-filtres.js#filtresActifs`) ; Ket l'annonce
 * depuis `LigneDesFiltres`. Les deux jouent le MÊME fichier de cas
 * (`tests/js/fixtures/ligne-des-filtres.json`, joué par `ligne-des-filtres-parite.test.mjs`).
 */
class LigneDesFiltresPariteTest extends KernelTestCase
{
    private const FIXTURE = __DIR__ . '/../js/fixtures/ligne-des-filtres.json';

    /** @return iterable<string, array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}> */
    public static function cas(): iterable
    {
        $fixture = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['cas'] as $cas) {
            yield $cas['nom'] => [$cas, $fixture['definitions']];
        }
    }

    /**
     * @param array<string, mixed>             $cas
     * @param array<int, array<string, mixed>> $definitions
     *
     * @dataProvider cas
     */
    public function testLaMemeLigneQueLEcran(array $cas, array $definitions): void
    {
        self::assertSame(
            $cas['attendu'],
            LigneDesFiltres::calculer($cas['criteres'], $definitions, $cas['groupes'], $cas['defauts'] ?? [], $cas['imposes'] ?? []),
        );
    }

    /**
     * Les groupes se lisent sur le CANEVAS comme la barre les lit dans le DOM rendu par
     * `_list_manager.html.twig` : titre du groupe, options à valeur, défaut d'un groupe sans
     * « Toutes », maille de lecture.
     */
    public function testLesGroupesSeLisentSurLeCanevasCommeDansLeDom(): void
    {
        self::bootKernel();
        $canevas = static::getContainer()->get(CanvasBuilder::class);
        $groupes = static fn (string $entite): array => array_column(
            LigneDesFiltres::groupesDuCanevas($canevas->getListeCanvas($entite)['filtres_predefinis'] ?? []),
            null,
            'cle',
        );

        $tranches = $groupes(Tranche::class);
        self::assertSame('Prime', $tranches['__paiement_prime__']['titre'] ?? null, 'le titre du groupe, tel que la pilule l\'affiche');
        self::assertNull($tranches['__paiement_prime__']['defaut'], 'un groupe avec « Toutes » n\'a pas de défaut imposé');

        $production = $groupes(ProductionScope::ENTITE);
        self::assertSame(ProductionScope::STATUT_PAR_DEFAUT, $production[ProductionScope::CLE_STATUT]['defaut'] ?? null);

        $retros = array_filter($groupes(ReversementRetroAgent::class), static fn (array $g): bool => $g['presentation']);
        self::assertCount(1, $retros, 'la maille de lecture des Rétros (groupé / détail)');
    }
}
