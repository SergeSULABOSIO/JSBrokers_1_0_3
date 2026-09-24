<?php

namespace App\Tests\Ai;

use App\Ai\Reglage\CatalogueDesReglages;
use App\Ai\Trousse\TrousseCatalogue;
use App\Services\Canvas\Provider\Icon\IconCanvasProvider;
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
     * CHAQUE ICÔNE SE RÉSOUT, ET SON FICHIER EXISTE.
     *
     * Un alias inconnu d'IconCanvasProvider ne lève AUCUNE erreur : `ux_icon` reçoit
     * `null` et la ligne perd sa pastille, au milieu de cinquante et une autres qui
     * en portent une. Cela se lit comme une anomalie de l'outil, pas comme une faute
     * de frappe dans une carte d'alias — donc on cherche au mauvais endroit.
     *
     * Le second contrôle va plus loin : l'alias peut exister et pointer un SVG absent
     * du disque, avec exactement le même symptôme. On vérifie donc le fichier.
     */
    public function testChaqueOutilPorteUneIconeQuiSeResout(): void
    {
        self::bootKernel();

        $provider = new IconCanvasProvider();
        $racine = \dirname(__DIR__, 2);
        $casses = [];

        foreach (CatalogueDesReglages::iconesDecrites() as $outil => $alias) {
            $resolu = $provider->resolveIconName($alias);
            if ($resolu === null) {
                $casses[] = sprintf('%s : l’alias « %s » est inconnu', $outil, $alias);
                continue;
            }

            [$set, $nom] = explode(':', $resolu, 2) + [1 => ''];
            $chemin = sprintf('%s/assets/icons/%s/%s.svg', $racine, $set, $nom);
            if (!is_file($chemin)) {
                $casses[] = sprintf('%s : « %s » n’est pas sur le disque (%s)', $outil, $resolu, $chemin);
            }
        }

        self::assertSame([], $casses, 'Ces outils afficheraient une pastille vide.');
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
