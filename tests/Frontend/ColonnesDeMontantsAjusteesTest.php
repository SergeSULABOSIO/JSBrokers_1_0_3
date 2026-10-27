<?php

namespace App\Tests\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * LES COLONNES DE MONTANTS PRENNENT LA LARGEUR DE LEUR CONTENU — la colonne principale, le reste.
 *
 * Mesuré le 2026-10-27 (refonte de la colonne 3, étape 0) : Tranches à 1 358 × 595, panneau de
 * Ket ouvert, la colonne principale tombait à 107 px — une ligne de 521 px de haut, AUCUNE ligne
 * visible — parce que chaque montant gardait 150 px, quel que fût son contenu. Les montants ne
 * se replient jamais (`.td-numeric`, `nowrap`) : ils n'ont besoin que de leur largeur, et la
 * colonne qui se lit en mots prend ce qui reste.
 *
 * Les collections en DIALOGUE ne sont pas concernées : leur `colgroup` fige une grille commune
 * à tous les onglets (`.table-enhanced:has(> colgroup)`), choix délibéré qui ne change pas.
 */
class ColonnesDeMontantsAjusteesTest extends TestCase
{
    private const GABARIT = __DIR__ . '/../../templates/components/_list_manager.html.twig';
    private const FEUILLE = __DIR__ . '/../../assets/styles/app.css';

    public function testAucuneColonneDeMontantNePorteDeLargeurFigee(): void
    {
        $gabarit = (string) file_get_contents(self::GABARIT);

        preg_match_all('/<th\b(?:[^>"]|"[^"]*")*>/', $gabarit, $balises);
        $entetes = array_filter($balises[0], static fn (string $b) => str_contains($b, 'th-numeric'));
        self::assertNotEmpty($entetes, 'Les entêtes de montants sont rendues par ce gabarit.');
        foreach ($entetes as $entete) {
            self::assertStringNotContainsString('style=', $entete, "Une largeur en ligne fige la colonne : $entete");
        }
    }

    public function testLesMontantsDeLaListeSAjustentAuContenu(): void
    {
        $gabarit = (string) file_get_contents(self::GABARIT);
        // La boucle des colonnes numériques de la LISTE (pas la colonne de valeur d'une collection).
        self::assertMatchesRegularExpression(
            '/for colonne in listeCanvas\.colonnes_numeriques.*?<th\b[^>]*class="th-numeric th-numeric--contenu"/s',
            $gabarit,
        );

        // Commentaires retirés : une déclaration mise en commentaire ne compte pas.
        $feuille = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::FEUILLE));
        self::assertMatchesRegularExpression(
            '/\.table-enhanced \.th-numeric--contenu\s*\{([^}]*)\}/',
            $feuille,
        );
        preg_match('/\.table-enhanced \.th-numeric--contenu\s*\{([^}]*)\}/', $feuille, $regle);
        // `width` exactement — ni `min-width` ni `max-width`.
        self::assertMatchesRegularExpression('/(?<![-\w])width:\s*1%;/', $regle[1], 'Largeur minimale : la colonne vaut son plus large montant.');
        // L'entête se replie sur ses mots : tenu sur une ligne, un intitulé plus long que ses
        // montants élargissait la colonne (Invités, Ket ouvert : défilement, une colonne perdue).
        self::assertStringNotContainsString('nowrap', $regle[1], 'L’entête d’un montant doit pouvoir se replier.');

        // Deux montants voisins restent séparés QUAND LA PLACE LE PERMET : l'entête ET les
        // cellules de la liste portent la marge, sous une requête sur la largeur de la zone du
        // tableau (sous 1 000 px, la marge coûtait une colonne visible, Ket ouvert).
        $ligne = (string) file_get_contents(__DIR__ . '/../../templates/components/_list_row.html.twig');
        self::assertMatchesRegularExpression(
            '/for colonne in listeCanvas\.colonnes_numeriques.*?<td class="[^"]*\btd-numeric--contenu\b/s',
            $ligne,
        );
        self::assertMatchesRegularExpression(
            '/\.jsb-list-manager > \.table-scroll-wrapper\s*\{[^}]*container:\s*jsb-tableau \/ inline-size;/',
            $feuille,
        );
        self::assertMatchesRegularExpression(
            '/@container jsb-tableau \(min-width: 1000px\)\s*\{\s*\.table-enhanced \.th-numeric--contenu,\s*\.table-enhanced \.td-numeric--contenu\s*\{[^}]*padding-left:/',
            $feuille,
        );
    }

    public function testLaColonneDeValeurDUneCollectionGardeSaGrille(): void
    {
        $gabarit = (string) file_get_contents(self::GABARIT);
        self::assertMatchesRegularExpression(
            '/if estCollection.*?<th scope="col" class="th-numeric">/s',
            $gabarit,
            'La colonne de valeur d’une collection reste réglée par son colgroup.',
        );
    }
}
