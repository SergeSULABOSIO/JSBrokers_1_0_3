<?php

namespace App\Tests\Ai;

use App\Ai\Reglage\CatalogueDesReglages;
use App\Ai\Trousse\TrousseCatalogue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AUCUN OUTIL SANS FICHE, AUCUNE FICHE SANS OUTIL.
 *
 * L'écran « Réglages de Ket » ne peut pas se dériver entièrement du code : le nom
 * courtier et la classe se décident, ils ne se déduisent pas. Une liste décidée à la
 * main dérive — c'est la leçon d'OutilsDePlan, dont la liste en dur avait divergé
 * deux fois en production.
 *
 * Ce test est la contrepartie : ajouter un outil au projet oblige à dire ce qu'il
 * fait et dans quelle classe il tombe, sinon la suite est rouge. Et retirer un outil
 * oblige à retirer sa fiche, faute de quoi la console décrirait une capacité que Ket
 * n'a plus.
 */
class ManifesteDesOutilsTest extends KernelTestCase
{
    public function testChaqueOutilDuConteneurAUneFiche(): void
    {
        self::bootKernel();

        $reels = array_map(
            static fn ($outil): string => $outil->name(),
            static::getContainer()->get(TrousseCatalogue::class)->tous(),
        );
        $decrits = CatalogueDesReglages::nomsDecrits();

        sort($reels);
        sort($decrits);

        self::assertSame(
            [],
            array_values(array_diff($reels, $decrits)),
            'Ces outils n’ont pas de fiche dans CatalogueDesReglages : ils s’afficheraient '
            . 'en console sous leur nom technique, sans que personne sache ce qu’ils font.'
        );
        self::assertSame(
            [],
            array_values(array_diff($decrits, $reels)),
            'Ces fiches décrivent des outils qui n’existent plus : la console annoncerait '
            . 'une capacité que Ket n’a pas.'
        );
    }

    /**
     * LE POIDS ANNONCÉ EST CELUI DU PAYLOAD, et il n'est jamais nul. Un outil à
     * 0 octet signalerait un schéma vide — donc une déclaration que le fournisseur
     * refuserait — ou, plus probablement, une formule cassée.
     */
    public function testChaqueOutilAUnPoidsMesurable(): void
    {
        self::bootKernel();

        $catalogue = static::getContainer()->get(CatalogueDesReglages::class);
        $sansPoids = [];

        foreach ($catalogue->outils() as $ligne) {
            if ($ligne['octets'] < 50 || $ligne['jetons'] < 1) {
                $sansPoids[] = $ligne['nom'];
            }
        }

        self::assertSame([], $sansPoids, 'Ces outils pèsent un poids invraisemblable.');
    }
}
