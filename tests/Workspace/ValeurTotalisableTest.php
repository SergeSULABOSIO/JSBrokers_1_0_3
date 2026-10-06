<?php

namespace App\Tests\Workspace;

use App\Controller\Admin\ControllerUtilsTrait;
use App\Services\CanvasBuilder;
use PHPUnit\Framework\TestCase;

/**
 * LE PIED SOMME EXACTEMENT CE QUE LA COLONNE MONTRE.
 *
 * Le total d'une collection et la cellule de chaque ligne doivent lire la MEME valeur, par
 * le MEME chemin. Twig la rend avec `attribute()`, qui regarde la propriete accessible
 * AVANT l'accesseur ; `valeurTotalisable()` doit donc faire pareil.
 *
 * L'ordre inverse — celui de PropertyAccessor laisse a lui-meme — paraissait plus robuste
 * et ne l'etait pas : partout ou une propriete calculee et un getter portent le meme nom,
 * le pied aurait annonce un nombre que sa propre colonne ne montrait pas, et des totaux
 * d'aujourd'hui auraient change sans que rien ne le signale.
 *
 * Ce test tient les quatre formes qu'une valeur peut prendre dans ce projet. La troisieme
 * est la seule qui distingue les deux ordres : c'est elle qui justifie ce fichier.
 */
class ValeurTotalisableTest extends TestCase
{
    /**
     * Un porteur du trait, reduit a ce qu'il faut pour appeler une methode privee.
     *
     * `loadAllCalculatedValues()` est neutralise : ici les valeurs sont deja posees, et ce
     * qu'on examine est la LECTURE, pas le calcul.
     */
    private function lecteur(): object
    {
        $builder = $this->createMock(CanvasBuilder::class);

        $porteur = new class ($builder) {
            use ControllerUtilsTrait;

            public function __construct(CanvasBuilder $canvasBuilder)
            {
                $this->canvasBuilder = $canvasBuilder;
            }

            public function lire(object $item, string $champ): float
            {
                return $this->valeurTotalisable($item, $champ);
            }

            /** Exigee par le trait, sans objet ici : on ne lit aucune collection. */
            protected function getCollectionMap(): array
            {
                return [];
            }
        };
        return $porteur;
    }

    /** La forme ordinaire : `loadAllCalculatedValues()` ecrit sur une propriete publique. */
    public function testUneProprietePubliqueEstLue(): void
    {
        $item = new class () {
            public ?float $montant_final = 1000.0;
        };

        self::assertSame(1000.0, $this->lecteur()->lire($item, 'montant_final'));
    }

    /**
     * UNE PROPRIETE PRIVEE PASSE PAR SON GETTER — et surtout ne fait plus tomber la page.
     *
     * `property_exists()` repondait `true` pour une propriete privee, et l'acces direct qui
     * suivait levait une `Error` fatale depuis le trait. `get_object_vars()`, appele hors
     * de la classe, ne voit que le public : ce cas descend desormais vers l'accesseur.
     */
    public function testUneProprieteePriveePasseParSonGetter(): void
    {
        $item = new class () {
            private float $primeTranche = 250.5;

            public function getPrimeTranche(): float
            {
                return $this->primeTranche;
            }
        };

        self::assertSame(250.5, $this->lecteur()->lire($item, 'primeTranche'));
    }

    /**
     * LE CAS QUI TRANCHE : propriete ET getter, deux valeurs. La PROPRIETE gagne.
     *
     * C'est le choix de Twig, donc celui de la cellule affichee. Un pied qui prendrait le
     * getter annoncerait ici 999 sous une colonne qui montre 42.
     */
    public function testLaProprieteLEmporteSurLeGetterCommeDansTwig(): void
    {
        $item = new class () {
            public ?float $montantTTC = 42.0;

            public function getMontantTTC(): float
            {
                return 999.0;
            }
        };

        self::assertSame(42.0, $this->lecteur()->lire($item, 'montantTTC'));
    }

    /** Une valeur posee dynamiquement reste visible : `get_object_vars()` les rend aussi. */
    public function testUneProprieteDynamiqueEstLue(): void
    {
        $item = new #[\AllowDynamicProperties] class () {
        };
        $item->montantCalculeTTC = 17.25;

        self::assertSame(17.25, $this->lecteur()->lire($item, 'montantCalculeTTC'));
    }

    /** Un champ introuvable n'ajoute rien : un total ne leve pas, il n'additionne pas. */
    public function testUnChampIntrouvableNAjouteRien(): void
    {
        $item = new class () {
            public ?float $autreChose = 5.0;
        };

        self::assertSame(0.0, $this->lecteur()->lire($item, 'montantAbsent'));
    }
}
