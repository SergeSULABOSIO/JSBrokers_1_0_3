<?php

namespace App\Tests\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * LA BARRE DU BAS ABRÈGE SES LIBELLÉS — JAMAIS UN MONTANT, JAMAIS UNE MONNAIE, JAMAIS UN NOMBRE.
 *
 * La barre du bas d'une liste (état, pagination, totaux) tient sur une ligne de hauteur
 * fixe, celle que le rail d'outils laisse libre. Quand la place manque, six paliers
 * s'appliquent dans l'ordre décidé (plan du 2026-10-27) ; mesuré en navigateur à 1 920,
 * 1 366, 1 358, 1 024 et 768 px, Ket ouvert et fermé, avec des totaux réalistes : aucun
 * débordement, chaque montant entier. Ce test garde ce qui rend cela vrai.
 *
 * Constaté en route : l'état coupé au milieu affichait « 20 affiché(s) sur 2 » pour 25 —
 * un nombre faux. L'état cède désormais par PARTIES ENTIÈRES, et passe à sa forme courte.
 */
class BarreDuBasMontantsEntiersTest extends TestCase
{
    private const FEUILLE = __DIR__ . '/../../assets/styles/app.css';
    private const CERVEAU = __DIR__ . '/../../assets/controllers/cerveau_controller.js';
    private const TOTAUX = __DIR__ . '/../../templates/components/_list_summary.html.twig';

    /** Les six paliers, du plus large au plus étroit (largeur de la zone de la liste). */
    private const PALIERS = ['1499.98', '1439.98', '1349.98', '1229.98', '939.98', '767.98'];

    private static function feuille(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::FEUILLE));
    }

    /** @return array<string, string> seuil => corps du bloc @container */
    private static function paliers(): array
    {
        preg_match_all('/@container jsb-zone \(max-width: ([\d.]+)px\)\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', self::feuille(), $m, PREG_SET_ORDER);
        $paliers = [];
        foreach ($m as $bloc) {
            $paliers[$bloc[1]] = $bloc[2];
        }

        return $paliers;
    }

    public function testLesSixPaliersExistentDansLOrdre(): void
    {
        self::assertSame(self::PALIERS, array_keys(self::paliers()));
    }

    public function testUnMontantNeSeCoupeNiNeSeReplie(): void
    {
        preg_match('/\.jsb-list-summary__item,\s*\.jsb-list-summary__item-value\s*\{([^}]*)\}/', self::feuille(), $regle);
        self::assertNotEmpty($regle, 'La règle des montants existe.');
        self::assertMatchesRegularExpression('/flex-shrink:\s*0;/', $regle[1]);
        self::assertMatchesRegularExpression('/white-space:\s*nowrap;/', $regle[1]);
        self::assertStringNotContainsString('text-overflow', $regle[1]);
    }

    public function testAucunPalierNeToucheAUnMontantNiAUneMonnaie(): void
    {
        foreach (self::paliers() as $seuil => $corps) {
            self::assertStringNotContainsString('item-value', $corps, "Le palier < {$seuil}px touche un montant.");
            self::assertStringNotContainsString('td-numeric-unit', $corps, "Le palier < {$seuil}px touche une monnaie.");
            self::assertStringNotContainsString('text-overflow', $corps, "Le palier < {$seuil}px coupe un texte.");
        }
    }

    public function testLeDernierPalierPasseSurDeuxLignesPourLeRailCommePourLaGrille(): void
    {
        $dernier = self::paliers()['767.98'] ?? '';
        self::assertMatchesRegularExpression('/\.context-toolbar--rail,\s*\.jsb-list-manager\s*\{\s*--jsb-barre-bas-h:\s*\d+px;/', $dernier);
        self::assertMatchesRegularExpression('/flex-wrap:\s*wrap;/', $dernier);
    }

    public function testLEtatCedeParPartiesEntieresEtSaitSeDireCourt(): void
    {
        $feuille = self::feuille();
        // L'état est dans la pastille qu'il partage avec la taille de page (2026-10-29).
        preg_match('/\.jsb-list-manager > \.jsb-list-controls-bar \.jsb-list-controls-bar__etat > \.jsb-list-controls-bar__display\s*\{([^}]*)\}/', $feuille, $etat);
        self::assertMatchesRegularExpression('/flex-wrap:\s*wrap;/', $etat[1] ?? '', 'Une partie qui ne tient pas passe à la ligne, hors de la vue.');
        self::assertMatchesRegularExpression('/overflow:\s*hidden;/', $etat[1] ?? '');

        $cerveau = (string) file_get_contents(self::CERVEAU);
        foreach (['jsb-etat__heure', 'jsb-etat__entite', 'jsb-etat__onglet', 'jsb-etat__compte', 'jsb-etat__selection', 'jsb-etat__long', 'jsb-etat__court'] as $classe) {
            self::assertStringContainsString($classe, $cerveau, "L'état publié porte « $classe ».");
        }
        // La forme courte est décorative : la longue reste lue par les lecteurs d'écran.
        self::assertSame(2, substr_count($cerveau, 'class="jsb-etat__court" aria-hidden="true"'));
    }

    public function testLesLibellesCourtsDesTotauxNeSontQueVisuels(): void
    {
        $totaux = (string) file_get_contents(self::TOTAUX);
        self::assertSame(2, substr_count($totaux, 'class="jsb-libelle-court" aria-hidden="true"'));
        self::assertStringContainsString('<span class="jsb-libelle-long">Total de la page</span>', $totaux);
        self::assertStringContainsString('<span class="jsb-libelle-long">Sélection</span>', $totaux);
    }
}
