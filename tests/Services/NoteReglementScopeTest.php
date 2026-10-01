<?php

namespace App\Tests\Services;

use App\Services\Search\NoteReglementScope;
use PHPUnit\Framework\TestCase;

/**
 * LA RÈGLE DE CLASSEMENT D'UNE NOTE, éprouvée sans base ni conteneur.
 *
 * ── POURQUOI CETTE RÈGLE VIT DANS UNE FONCTION PURE ─────────────────────────────────
 * Elle était écrite DEUX FOIS dans `NoteIndicatorStrategy` — en `if` pour les notes à
 * articles, en `match` pour les notes de bordereau. Et la première ignorait le chemin du
 * bordereau : appelée seule sur une note de bordereau, elle lisait des articles vides et
 * répondait « N/A » ou « Payée » à tort. Deux copies d'une même règle finissent toujours
 * par diverger ; celle-ci n'en a plus qu'une, et elle s'éprouve en une milliseconde.
 *
 * Ce banc tient aussi les BORNES, qui sont le seul endroit où une telle règle se trompe.
 */
class NoteReglementScopeTest extends TestCase
{
    /**
     * ⚠ L'ORDRE DES TESTS COMPTE, ET C'EST TOUT L'ENJEU DE CE CAS.
     *
     * Sur une note à zéro, le payé est trivialement supérieur ou égal au dû. Juger « tout
     * est payé » avant « il n'y a rien à réclamer » la rangerait donc parmi les notes
     * réglées — et gonflerait le compte de ce qui a été encaissé avec des notes vides.
     */
    public function testUneNoteQuiNeReclameRienNEstPasReglee(): void
    {
        self::assertSame(
            NoteReglementScope::SANS_MONTANT,
            NoteReglementScope::statut(0.0, 0.0),
        );
        self::assertFalse(NoteReglementScope::resteADue(0.0, 0.0),
            'Rien n\'est dû sur une note sans montant.',
        );
    }

    public function testRienDEncaisseEstImpayee(): void
    {
        self::assertSame(NoteReglementScope::IMPAYEE, NoteReglementScope::statut(1160.0, 0.0));
        self::assertTrue(NoteReglementScope::resteADue(1160.0, 0.0));
    }

    public function testUnEncaissementIncompletEstPartiel(): void
    {
        self::assertSame(NoteReglementScope::PARTIELLE, NoteReglementScope::statut(1160.0, 400.0));
        self::assertTrue(NoteReglementScope::resteADue(1160.0, 400.0),
            'Une note partiellement réglée a encore un solde : c\'est ce qui la distingue '
            . 'd\'une note soldée, et ce qui justifie le bouton de règlement.',
        );
    }

    public function testToutEncaisseEstReglee(): void
    {
        self::assertSame(NoteReglementScope::REGLEE, NoteReglementScope::statut(1160.0, 1160.0));
        self::assertFalse(NoteReglementScope::resteADue(1160.0, 1160.0));
    }

    /** Un trop-perçu reste une note réglée : on ne réclame pas ce qui a été versé en trop. */
    public function testUnTropPercuResteRegle(): void
    {
        self::assertSame(NoteReglementScope::REGLEE, NoteReglementScope::statut(1160.0, 1200.0));
    }

    /**
     * LE SEUIL, ET POURQUOI IL EST UNIQUE.
     *
     * Trois seuils cohabitaient dans le projet — 0,01 chez `SourceDeFacturation`, 0,005 chez
     * `NoteRecouvrementService`, et un `> 0.01` dans l'indicateur sous un commentaire
     * affirmant qu'il partageait le premier. Une note à sept millièmes pouvait donc être
     * impayée pour l'un et soldée pour l'autre. Celui-ci est le seul que le classement lit.
     */
    public function testUnResteSousLeSeuilNEstPasUneCreance(): void
    {
        self::assertSame(
            NoteReglementScope::REGLEE,
            NoteReglementScope::statut(1000.0, 999.995),
            'Un demi-centime manquant relève de l\'arrondi comptable, pas d\'une créance.',
        );
        self::assertSame(
            NoteReglementScope::PARTIELLE,
            NoteReglementScope::statut(1000.0, 900.0),
            'Cent euros manquants, eux, sont bien une créance.',
        );
    }

    /** Un versement infime sur une note due ne la fait pas passer pour entamée. */
    public function testUnVersementNegligeableLaisseLaNoteImpayee(): void
    {
        self::assertSame(NoteReglementScope::IMPAYEE, NoteReglementScope::statut(1000.0, 0.004));
    }

    /**
     * LES QUATRE ÉTATS PARTITIONNENT LE MONDE : toute note tombe dans un et un seul.
     * Sans quoi un chip laisserait des notes invisibles de tous les autres.
     */
    public function testLesQuatreEtatsCouvrentTousLesCas(): void
    {
        $cas = [
            [0.0, 0.0], [1000.0, 0.0], [1000.0, 500.0], [1000.0, 1000.0],
            [1000.0, 2000.0], [-500.0, 0.0], [0.0, 250.0],
        ];

        foreach ($cas as [$du, $paye]) {
            self::assertArrayHasKey(
                NoteReglementScope::statut($du, $paye),
                NoteReglementScope::ETATS,
                sprintf('Dû %s, payé %s : le classement doit rendre un état connu.', $du, $paye),
            );
        }
    }

    /**
     * LES LIBELLÉS AFFICHÉS NE CHANGENT PAS.
     *
     * La colonne « Statut » de chaque ligne disait déjà ces quatre mots. Les renommer en
     * passant par le scope aurait été une régression visuelle que personne n'a demandée —
     * et que rien, à part ce test, n'aurait signalée.
     */
    public function testLesMotsDeLaColonneStatutSontInchanges(): void
    {
        self::assertSame('Impayée', NoteReglementScope::libelleAffichage(NoteReglementScope::IMPAYEE));
        self::assertSame('Partiel', NoteReglementScope::libelleAffichage(NoteReglementScope::PARTIELLE));
        self::assertSame('Payée', NoteReglementScope::libelleAffichage(NoteReglementScope::REGLEE));
        self::assertSame('N/A', NoteReglementScope::libelleAffichage(NoteReglementScope::SANS_MONTANT));
    }

    /**
     * LE CRITÈRE NE S'ANNONCE PAS S'IL NE PEUT PAS S'APPLIQUER. Un filtre qu'on prétend
     * poser sans savoir le traduire laisse un badge qui ment sur ce qu'affiche la liste.
     */
    public function testUnCritereInconnuOuHorsNoteNEstPasFabrique(): void
    {
        self::assertSame([], NoteReglementScope::critereRecherche('Note', 'inconnu'));
        self::assertSame([], NoteReglementScope::critereRecherche('Note', null));
        self::assertSame([], NoteReglementScope::critereRecherche('Tranche', NoteReglementScope::IMPAYEE));

        $critere = NoteReglementScope::critereRecherche('Note', NoteReglementScope::IMPAYEE);
        self::assertSame(
            [NoteReglementScope::CRITERION_KEY => [
                'operator' => '=', 'value' => 'impayee', 'label' => 'Note impayée',
            ]],
            $critere,
        );
    }

    /**
     * ⚠ LA CLÉ SE RETIRE MÊME QUAND SA VALEUR EST INVALIDE.
     *
     * `__reglement_note__` n'est pas une colonne. Laissée dans les critères parce que sa
     * valeur ne voulait rien dire, elle ferait lever Doctrine sur un champ inconnu dès que
     * la recherche standard reprend — une erreur 500 pour un filtre mal tapé.
     */
    public function testLaCleEstDetecteeMemeInvalideEtToujoursRetirable(): void
    {
        $criteres = [NoteReglementScope::CRITERION_KEY => ['operator' => '=', 'value' => 'farfelu']];

        self::assertTrue(NoteReglementScope::porteLeCritere($criteres),
            'La clé est là : le moteur doit emprunter le chemin qui sait la retirer.',
        );
        self::assertNull(NoteReglementScope::extraireValeur($criteres),
            'Mais sa valeur ne désigne aucun état : on ne filtre pas au hasard.',
        );
        self::assertSame([], NoteReglementScope::retirerCritere($criteres));
    }

    /** La valeur se lit aussi bien enveloppée que nue — les deux formes circulent. */
    public function testLaValeurSeLitEnveloppeeOuNue(): void
    {
        self::assertSame(
            NoteReglementScope::REGLEE,
            NoteReglementScope::extraireValeur([NoteReglementScope::CRITERION_KEY => ['value' => 'reglee']]),
        );
        self::assertSame(
            NoteReglementScope::REGLEE,
            NoteReglementScope::extraireValeur([NoteReglementScope::CRITERION_KEY => 'reglee']),
        );
        self::assertNull(NoteReglementScope::extraireValeur([]));
    }
}
