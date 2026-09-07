<?php

namespace App\Tests\Workspace;

use PHPUnit\Framework\TestCase;

/**
 * LES SEUILS DE DISPOSITION SONT LES MÊMES DANS LES TROIS FICHIERS QUI LES PORTENT.
 *
 * L'espace de travail change de disposition à deux largeurs : à 1199.98 px la colonne 2
 * passe en panneau flottant, à 767.98 px la colonne 1 quitte le flux et devient un tiroir.
 * Ces deux nombres sont écrits TROIS FOIS, et ils ne peuvent pas l'être une seule :
 *
 *  - `interactive-menu.css` en media query — qui ne sait pas lire une variable CSS ;
 *  - `workspace-manager_controller.js` en `matchMedia` — le contrôleur doit replier la
 *    colonne au moment exact où la feuille de style change de disposition ;
 *  - le script anti-clignotement du gabarit — qui pose la classe AVANT le premier rendu,
 *    donc avant que Stimulus existe.
 *
 * ── CE QUI ARRIVERAIT SANS CE TEST ───────────────────────────────────────────────────
 * Déplacer un seuil dans un seul fichier ne casse RIEN de visible immédiatement : chaque
 * fichier reste valide. Simplement, entre les deux valeurs, s'ouvre une bande de largeurs
 * où le CSS croit la colonne dépliée pendant que le contrôleur la replie — ou l'inverse.
 * La colonne 2 se pose alors en `position: fixed` sans que la colonne 3 récupère la place,
 * ou occupe 350 px dans le flux sans que rien ne l'y autorise. Le défaut ne se voit qu'à
 * une largeur précise, et jamais sur le poste de celui qui a fait le changement.
 *
 * Même famille de garde-fou que `ChipsCoherenceContratTest` et `ContratDesActionsTest` :
 * une valeur traverse plusieurs langages, et aucun d'eux ne force l'accord.
 */
class AdaptationEcransEtroitsTest extends TestCase
{
    private const CSS = __DIR__ . '/../../assets/styles/interactive-menu.css';
    private const CONTROLEUR = __DIR__ . '/../../assets/controllers/workspace-manager_controller.js';
    private const GABARIT = __DIR__ . '/../../templates/components/_interactive_menu.html.twig';

    /** Le seuil sous lequel la colonne 2 passe en panneau flottant. */
    private const SEUIL_COL2 = '1199.98px';

    /** Le seuil sous lequel la colonne 1 devient un tiroir. */
    private const SEUIL_TIROIR = '767.98px';

    private function lire(string $chemin): string
    {
        self::assertFileExists($chemin);

        return (string) file_get_contents($chemin);
    }

    /**
     * LES TROIS FICHIERS PARLENT DE LA MÊME LARGEUR.
     */
    public function testLesSeuilsConcordentEntreCssJsEtGabarit(): void
    {
        $css = $this->lire(self::CSS);
        $controleur = $this->lire(self::CONTROLEUR);
        $gabarit = $this->lire(self::GABARIT);

        foreach ([self::SEUIL_COL2, self::SEUIL_TIROIR] as $seuil) {
            self::assertStringContainsString(
                "@media (max-width: {$seuil})",
                $css,
                "La feuille de style doit porter une media query au seuil {$seuil}.",
            );
            self::assertStringContainsString(
                "(max-width: {$seuil})",
                $controleur,
                "Le contrôleur doit observer le MÊME seuil {$seuil} que la feuille de style.",
            );
        }

        // Le gabarit ne connaît que le premier seuil : lui seul décide de la classe
        // posée avant le premier rendu.
        self::assertStringContainsString(
            'window.matchMedia',
            $gabarit,
            'Le script anti-clignotement doit consulter la largeur, pas seulement la préférence.',
        );
        self::assertStringContainsString(
            '(max-width: ' . self::SEUIL_COL2 . ')',
            $gabarit,
            'Le script anti-clignotement doit lire le même seuil que le contrôleur.',
        );
    }

    /**
     * LE PLANCHER DE LARGEUR EST BIEN LEVÉ.
     *
     * C'était LA cause du blocage : `min-width` en pixels sur les colonnes 1, 2 et 4
     * réservait 930 px avant que l'espace de travail reçoive quoi que ce soit. Sous
     * ~1100 px la colonne 3 était écrasée à zéro, et comme la coquille ne défile pas,
     * son contenu devenait inatteignable — pas même une barre pour aller le chercher.
     */
    public function testLePlancherDeLargeurTombeSousLeSeuil(): void
    {
        $css = $this->lire(self::CSS);

        self::assertMatchesRegularExpression(
            '/@media \(max-width: ' . preg_quote(self::SEUIL_COL2, '/') . '\)\s*\{.*?\.menu-col-2\s*\{[^}]*min-width:\s*0/s',
            $css,
            'Sous le seuil, la colonne 2 doit pouvoir céder du terrain.',
        );
        self::assertMatchesRegularExpression(
            '/@media \(max-width: ' . preg_quote(self::SEUIL_COL2, '/') . '\)\s*\{.*?\.menu-col-4\s*\{[^}]*min-width:\s*0/s',
            $css,
            'Sous le seuil, la colonne 4 ne doit plus imposer 450 px à la zone de travail.',
        );
    }

    /**
     * LA PAGE NE BLOQUE PLUS SON DÉFILEMENT PARTOUT.
     *
     * `overflow: hidden` était posé sur `body, html` sans condition, alors que cette
     * feuille est chargée sur CHAQUE page. La vitrine devait le défaire à coups de
     * `!important`, et surtout : ce qui débordait sur un écran étroit n'était atteignable
     * par aucun moyen. La règle doit rester portée par l'espace de travail seul.
     */
    public function testLeBlocageDuDefilementEstReserveAuWorkspace(): void
    {
        $css = $this->lire(self::CSS);

        self::assertDoesNotMatchRegularExpression(
            '/^body,\s*\n\s*html\s*\{[^}]*overflow:\s*hidden/m',
            $css,
            "`overflow: hidden` ne doit plus être imposé à toutes les pages du site.",
        );
        self::assertMatchesRegularExpression(
            '/body:has\(\.interactive-menu\)\s*\{[^}]*overflow:\s*hidden/s',
            $css,
            "L'espace de travail reste une coquille qui ne défile pas : ce sont ses panneaux qui défilent.",
        );
    }

    /**
     * LA HAUTEUR SUIT LE VIEWPORT RÉELLEMENT VISIBLE.
     *
     * `100vh` ignore la barre d'URL des navigateurs mobiles : la coquille dépassait par
     * le bas, et sa dernière rangée — la zone de saisie du chat, le pied d'une liste —
     * restait sous le pli, hors d'atteinte puisque la page ne défile pas.
     */
    public function testLaCoquilleSuitLaHauteurVisibleSurMobile(): void
    {
        $css = $this->lire(self::CSS);

        self::assertMatchesRegularExpression(
            '/\.interactive-menu\s*\{[^}]*height:\s*100dvh/s',
            $css,
            'La coquille doit se mesurer sur le viewport réellement visible.',
        );
        self::assertMatchesRegularExpression(
            '/\.interactive-menu\s*\{[^}]*height:\s*100vh/s',
            $css,
            'Le `vh` doit rester en repli pour les navigateurs qui ignorent `dvh`.',
        );
    }

    /**
     * LE TIROIR EST COMMANDÉ, ET IL SAIT SE REFERMER.
     *
     * Un tiroir qui s'ouvre sans se refermer couvre l'espace de travail pour de bon.
     * Trois sorties doivent exister — le geste inverse, Échap, et le choix d'une
     * rubrique — sans quoi il ne resterait que le rechargement de la page.
     */
    public function testLeTiroirSeRefermeParTroisChemins(): void
    {
        $controleur = $this->lire(self::CONTROLEUR);

        self::assertStringContainsString('ws-tiroir-ouvert', $controleur);
        self::assertStringContainsString('_fermerTiroir()', $controleur);
        self::assertGreaterThanOrEqual(
            3,
            substr_count($controleur, 'this._fermerTiroir();'),
            'Le tiroir doit se refermer au geste inverse, à Échap et au choix d\'une rubrique.',
        );

        $css = $this->lire(self::CSS);
        self::assertMatchesRegularExpression(
            '/\.ws-tiroir-ouvert \.menu-col-1\s*\{[^}]*transform:\s*translateX\(0\)/s',
            $css,
            'La classe posée par le contrôleur doit bien faire sortir le tiroir.',
        );
    }

    /**
     * UN REPLI IMPOSÉ PAR L'ÉCRAN N'EST PAS UN CHOIX, ET NE S'ÉCRIT PAS.
     *
     * La préférence de repli est persistée par entreprise. Si la contrainte de largeur
     * l'écrasait, un passage sur tablette suffirait à replier la colonne pour toujours,
     * y compris de retour sur un grand écran — sans que personne ne l'ait demandé.
     */
    public function testLaContrainteDEcranNEcrasePasLaPreference(): void
    {
        $controleur = $this->lire(self::CONTROLEUR);

        self::assertMatchesRegularExpression(
            '/if \(!this\._contrainteCol2\(\)\)\s*\{\s*this\._ecrireCol2Pref\(replie\);/s',
            $controleur,
            'La préférence ne doit être écrite que hors contrainte de largeur.',
        );
    }
}
