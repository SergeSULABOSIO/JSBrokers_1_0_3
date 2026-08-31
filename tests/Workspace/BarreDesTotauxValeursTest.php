<?php

namespace App\Tests\Workspace;

use App\Entity\Invite;
use App\Entity\PaiementPrime;
use App\Entity\Partenaire;
use App\Entity\Portefeuille;
use App\Services\CanvasBuilder;
use App\Services\Canvas\NumericCanvasProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA BARRE ET LA CELLULE DOIVENT RACONTER LE MÊME CHIFFRE.
 *
 * Le test frère (BarreDesTotauxSuitLesColonnesTest) tient la liste des options ; celui-ci
 * tient les VALEURS. C'est une question distincte, et elle a sa propre histoire : les deux
 * surfaces ne lisent pas au même endroit. La cellule fait `attribute(entity, code)` en
 * Twig ; la barre somme un JSON produit en PHP. Deux lectures, deux échelles — la seconde
 * est en CENTIMES — et donc deux occasions de diverger sans que rien ne le dise.
 *
 * ── LES TROIS FAÇONS DONT UNE VALEUR SE LAISSE LIRE ─────────────────────────────────
 * Ce test les couvre toutes les trois, parce que chacune a piégé quelqu'un :
 *
 *   • propriété publique DÉCLARÉE       Portefeuille::$primeTotale
 *   • propriété DYNAMIQUE, posée par     Invite::$montantTTC — que rien ne déclare
 *     CanvasBuilder::loadAllCalculatedValues()
 *   • propriété PRIVÉE + accesseur       PaiementPrime::$montant / getMontant()
 *
 * Le troisième cas est celui qui aurait fait échouer un mécanisme naïf : un
 * `property_exists()` suivi d'un accès direct ne voit pas un champ privé, et aurait rendu
 * la seule colonne de la rubrique Paiements de prime intotalisable — en silence.
 */
class BarreDesTotauxValeursTest extends KernelTestCase
{
    protected function setUp(): void
    {
        static::bootKernel();
    }

    private function canevas(object $entite): array
    {
        return static::getContainer()->get(NumericCanvasProvider::class)->getAttributesAndValues($entite);
    }

    // ===================== L'échelle : des centimes, toujours =====================

    /**
     * LE CONTRAT DE `list-summary_controller.js` : il divise par 100 avant de formater.
     * Une rétro-commission de 200,00 doit donc arriver à 20000.
     */
    public function testLesMontantsSontEnCentimes(): void
    {
        $partenaire = new Partenaire();
        $partenaire->retroCommission = 200.0;

        self::assertSame(20000.0, $this->canevas($partenaire)['retroCommission']['value']);
    }

    /**
     * L'ARRONDI EST FAIT UNE FOIS, SUR LE RÉSULTAT.
     *
     * `407.24 * 100` vaut 40723.999999999996 en flottant. Sans arrondi, une page de vingt
     * lignes accumulerait ces poussières et la barre afficherait un total qui ne tombe
     * jamais juste — un centime d'écart que personne ne saurait expliquer.
     */
    public function testLaConversionNAccumulePasDePoussiereFlottante(): void
    {
        $partenaire = new Partenaire();
        $partenaire->montantPur = 407.24;

        self::assertSame(40724.0, $this->canevas($partenaire)['montantPur']['value']);
    }

    // ===================== Les trois modes de lecture =====================

    /** Propriété publique déclarée — le cas ordinaire. */
    public function testUneProprieteDeclareeEstLue(): void
    {
        $portefeuille = new Portefeuille();
        $portefeuille->primeTotale = 1500.0;
        $portefeuille->reserve = 42.5;

        $canevas = $this->canevas($portefeuille);

        self::assertSame(150000.0, $canevas['primeTotale']['value']);
        self::assertSame(4250.0, $canevas['reserve']['value']);
        self::assertSame(0.0, $canevas['montantTTC']['value'], 'Non renseignée : zéro, et non absente.');
    }

    /**
     * Propriété DYNAMIQUE : `montantTTC` n'est déclarée nulle part sur `Invite`, c'est
     * `InviteIndicatorStrategy` qui la pose — exactement comme le fait ici la ligne
     * d'affectation, et comme `CanvasBuilder::loadAllCalculatedValues()` le fait en vrai.
     */
    public function testUneProprieteDynamiqueEstLueCommeParTwig(): void
    {
        $invite = new Invite();
        $invite->montantTTC = 800.0;

        self::assertSame(80000.0, $this->canevas($invite)['montantTTC']['value']);
    }

    /**
     * UN CHAMP PRIVÉ SE LIT PAR SON ACCESSEUR.
     *
     * `PaiementPrime::$montant` est une colonne Doctrine privée, exposée par `getMontant()`.
     * Le tableau l'affiche parce que Twig essaie la méthode quand la propriété n'est pas
     * accessible. La barre doit faire de même, sans quoi elle resterait muette au-dessus
     * d'une colonne pleine.
     */
    public function testUnChampPriveEstLuParSonAccesseur(): void
    {
        $paiement = (new PaiementPrime())->setMontant(1234.56);

        $canevas = $this->canevas($paiement);

        self::assertArrayHasKey('montant', $canevas);
        self::assertSame(123456.0, $canevas['montant']['value']);
        self::assertSame('Montant réglé', $canevas['montant']['description']);
    }

    // ===================== Le maillon réel : la collection =====================

    /**
     * CE QUE LE CONTRÔLEUR ENVOIE VRAIMENT À L'ÉCRAN.
     *
     * `ControllerUtilsTrait` ne construit pas le canevas entité par entité : il appelle
     * `getNumericAttributesAndValuesForCollection()`, qui indexe PAR IDENTIFIANT — la clé
     * que la barre croise ensuite avec les cases cochées. Une entité au canevas vide n'y
     * est pas indexée du tout, et le JS bascule alors en « Aucune valeur numérique à
     * calculer ». Ce test vérifie le maillon lui-même, pas seulement sa pièce.
     */
    public function testLaCollectionEstIndexeeParIdentifiantEtNonVide(): void
    {
        $partenaire = new Partenaire();
        $partenaire->retroCommissionExigible = 75.0;
        // L'identifiant n'est jamais nul en conditions réelles ; on le pose sans base.
        $reflexion = new \ReflectionProperty(Partenaire::class, 'id');
        $reflexion->setValue($partenaire, 7);

        $collection = static::getContainer()->get(CanvasBuilder::class)
            ->getNumericAttributesAndValuesForCollection([$partenaire]);

        self::assertArrayHasKey(7, $collection);
        self::assertSame(7500.0, $collection[7]['retroCommissionExigible']['value']);
    }
}
