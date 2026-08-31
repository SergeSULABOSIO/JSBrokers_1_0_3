<?php

namespace App\Tests\Workspace;

use App\Entity\Invite;
use App\Entity\PaiementPrime;
use App\Entity\Partenaire;
use App\Entity\Portefeuille;
use App\Services\Canvas\ListCanvasProvider;
use App\Services\Canvas\NumericCanvasProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA BARRE DES TOTAUX NE PROPOSE QUE CE QUE LE TABLEAU MONTRE.
 *
 * Deux fichiers déclaraient des nombres sans se connaître : les `colonnes_numeriques` du
 * canevas de liste, et le dictionnaire du canevas numérique. Sur la rubrique
 * Intermédiaires, le tableau affichait six colonnes et le sélecteur vingt-huit options.
 * La première d'entre elles — donc celle présélectionnée à l'ouverture — était
 * « Nb. Pistes », que `list-summary_controller.js` rend par `formatCurrency()` : onze
 * pistes s'affichaient « Total 11,00 $US ». Un compte présenté comme un montant, dans un
 * outil dont le métier est l'argent.
 *
 * Deux autres rubriques disaient l'inverse : Portefeuilles et Paiements de prime
 * affichaient des colonnes d'argent sous une barre annonçant « Aucune valeur numérique à
 * calculer », faute d'un NumericCanvasProvider.
 *
 * ── CE TEST TIENT L'ÉGALITÉ, PAS SON CONTENU ────────────────────────────────────────
 * Il ne recopie aucune liste de codes : il compare les deux canevas L'UN À L'AUTRE.
 * Ajouter une colonne, la renommer ou la déplacer le laisse vert — c'est le propre d'une
 * règle qui se dérive au lieu de se maintenir. Rouvrir l'écart entre les deux surfaces le
 * fait tomber.
 *
 * Il interroge l'AIGUILLEUR et non les providers concrets : c'est le chemin réel de
 * l'application, et cela verrouille au passage la résolution de `supports()`.
 */
class BarreDesTotauxSuitLesColonnesTest extends KernelTestCase
{
    /**
     * Les rubriques dont la barre est adossée au tableau. Périmètre décidé : les autres
     * (Tranches, Clients, Avenants…) gardent leurs indicateurs propres, souvent utiles
     * hors colonnes — les y contraindre appauvrirait des barres que personne n'a
     * signalées.
     */
    private const RUBRIQUES = [
        Partenaire::class,
        Invite::class,
        Portefeuille::class,
        PaiementPrime::class,
    ];

    protected function setUp(): void
    {
        static::bootKernel();
    }

    private function canevasNumerique(object $entite): array
    {
        return static::getContainer()->get(NumericCanvasProvider::class)->getAttributesAndValues($entite);
    }

    /** Les colonnes de l'écran, taux exclus : code => titre affiché en tête de colonne. */
    private function colonnesAttendues(string $classe): array
    {
        $attendues = [];
        $canvas = static::getContainer()->get(ListCanvasProvider::class)->getCanvas($classe);
        foreach ($canvas['colonnes_numeriques'] ?? [] as $colonne) {
            if (($colonne['attribut_unité'] ?? null) === '%') {
                continue;
            }
            $attendues[$colonne['attribut_code']] = $colonne['titre_colonne'];
        }

        return $attendues;
    }

    // ===================== L'égalité des deux surfaces =====================

    /**
     * MÊME ENSEMBLE, ET MÊME ORDRE. L'ordre compte : le sélecteur présélectionne sa
     * première option, et l'œil s'attend à la retrouver en tête de tableau.
     */
    public function testLesOptionsSontExactementLesColonnesNonTaux(): void
    {
        foreach (self::RUBRIQUES as $classe) {
            self::assertSame(
                array_keys($this->colonnesAttendues($classe)),
                array_keys($this->canevasNumerique(new $classe())),
                sprintf('%s : la barre des totaux et le tableau ont divergé.', $classe),
            );
        }
    }

    /** Le libellé de l'option EST le titre de la colonne : l'usager lit le même mot. */
    public function testChaqueOptionPorteLeTitreDeSaColonne(): void
    {
        foreach (self::RUBRIQUES as $classe) {
            $canevas = $this->canevasNumerique(new $classe());
            foreach ($this->colonnesAttendues($classe) as $code => $titre) {
                self::assertSame(
                    $titre,
                    $canevas[$code]['description'],
                    sprintf('%s : « %s » ne porte pas le titre de sa colonne.', $classe, $code),
                );
            }
        }
    }

    /**
     * AUCUN TAUX DANS LE SÉLECTEUR.
     *
     * « Part (%) » ouvre le tableau des Intermédiaires. La somme des parts de dix
     * partenaires n'est pas une part — et le contrôleur JS ne saurait pas l'écrire : il
     * ignore le `unit: '%'` que le PHP produisait, et rendrait « 150 % » en « 1,50 $US ».
     */
    public function testAucunTauxNEstProposeALaSomme(): void
    {
        $canevas = $this->canevasNumerique(new Partenaire());

        self::assertArrayNotHasKey('partPourcentage', $canevas);
        self::assertCount(5, $canevas, 'Six colonnes dont un taux : cinq options.');
    }

    /** Plus aucun compteur : « Nb. Pistes » n'était pas de l'argent. */
    public function testLesCompteursDuPartenaireNeSontPlusTotalisables(): void
    {
        $canevas = $this->canevasNumerique(new Partenaire());

        foreach (['nombrePistesApportees', 'nombreClientsAssocies', 'nombrePolicesGenerees'] as $compteur) {
            self::assertArrayNotHasKey(
                $compteur,
                $canevas,
                sprintf('« %s » est un compte, pas un montant : la barre le rendrait en monnaie.', $compteur),
            );
        }
    }

    // ===================== La forme est celle de la CLASSE, pas de l'instance =====

    /**
     * LE SÉLECTEUR EST BÂTI SUR LA PREMIÈRE LIGNE SEULEMENT.
     *
     * `list-summary_controller.js` prend `Object.values(data)[0]` comme modèle des
     * options. Une entité vierge — aucune valeur calculée, aucune propriété dynamique
     * posée — doit donc porter TOUTES les clés, à zéro. Sinon un partenaire sans affaire
     * en tête de page ferait disparaître les options pour la page entière.
     *
     * C'est le mode dégradé que l'ancien mécanisme laissait ouvert : il déduisait le
     * canevas de l'INSTANCE (`property_exists`), quand celui-ci se déduit maintenant de
     * la CLASSE.
     */
    public function testUneEntiteViergePorteToutesLesClesAZero(): void
    {
        foreach (self::RUBRIQUES as $classe) {
            $canevas = $this->canevasNumerique(new $classe());

            self::assertNotEmpty($canevas, sprintf('%s : la barre serait muette.', $classe));
            foreach ($canevas as $code => $entree) {
                self::assertSame(
                    0.0,
                    (float) $entree['value'],
                    sprintf('%s : « %s » devrait valoir zéro, pas manquer.', $classe, $code),
                );
            }
        }
    }

    /**
     * TROIS DES HUIT COLONNES DE L'INVITÉ SONT DES PROPRIÉTÉS DYNAMIQUES.
     *
     * `primeTotale`, `montantTTC` et `montantPur` ne sont déclarées nulle part sur
     * `Invite` : `InviteIndicatorStrategy` les pose à la volée, et rend un tableau vide
     * quand l'invité n'a pas d'entreprise. L'ancien dictionnaire ne les publiait donc que
     * si la stratégie avait tourné. Ici, elles existent même sur un invité nu.
     */
    public function testLesColonnesDynamiquesDeLInviteExistentMemeSansCalcul(): void
    {
        $canevas = $this->canevasNumerique(new Invite());

        foreach (['primeTotale', 'montantTTC', 'montantPur'] as $code) {
            self::assertArrayHasKey($code, $canevas);
        }
        self::assertCount(8, $canevas);
    }

    // ===================== Les deux rubriques réveillées =====================

    /**
     * Ces deux rubriques affichaient des colonnes d'argent sous une barre qui se déclarait
     * sans rien à compter. Ce n'était pas un choix : aucun provider n'existait.
     */
    public function testLesRubriquesAutrefoisMuettesTotalisentDesormais(): void
    {
        self::assertCount(4, $this->canevasNumerique(new Portefeuille()));
        self::assertCount(1, $this->canevasNumerique(new PaiementPrime()));
    }
}
