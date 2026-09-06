<?php

namespace App\Echange\Etat;

use App\Echange\Classeur\EcrivainJsbx;

/**
 * LA CHARTE GRAPHIQUE JS BROKERS, TRADUITE POUR UN CLASSEUR.
 *
 * ⚠ AUCUNE COULEUR NE S'ÉCRIT AILLEURS QUE DANS CETTE CLASSE. Un fichier qui sort du
 * cabinet porte la marque au même titre qu'une page de l'application : un bleu choisi à
 * l'œil dans un écrivain, et le classeur ne ressemble plus à rien de ce que le client a
 * déjà vu. Les valeurs viennent une à une du skill `charte-couleurs`, et le nom du jeton
 * d'origine est rappelé en regard pour qu'on puisse les confronter.
 *
 * ⚠ ET LES ASSOCIATIONS FOND / TEXTE NE SONT PAS LIBRES. La charte les fixe (règle 2), et
 * elles portent le contraste : blanc sur cobalt, `#664d03` sur `#fff3cd`, `#212529` sur
 * les fonds clairs. Les méthodes `surCobalt()`, `surAvertissement()`… rendent le couple
 * indissociable plutôt que de laisser choisir les deux moitiés séparément.
 *
 * Le format est celui d'Excel — ARGB, l'alpha en tête : `FF` + les six chiffres du hex.
 */
final class Charte
{
    // ── Marque ──────────────────────────────────────────────────────────────────────
    /** `--cobalt` #0047AB — couleur principale de la marque. */
    public const COBALT = 'FF' . EcrivainJsbx::COBALT;

    /** `--cobalt-dark` #003380 — second temps du cobalt, pour alterner sans inventer. */
    public const COBALT_SOMBRE = 'FF003380';

    /** `--bg-cobalt-subtle` #e8f0fb — fond très clair, pour les lignes de regroupement. */
    public const COBALT_TRES_CLAIR = 'FFE8F0FB';

    // ── Fonds et surfaces ───────────────────────────────────────────────────────────
    /** `--bg-white` #ffffff. */
    public const BLANC = 'FFFFFFFF';

    /** `--bg-light` #f8f9fa — le ton pâle des lignes alternées. */
    public const GRIS_PALE = 'FFF8F9FA';

    /** `--bg-muted` #e9ecef — séparateurs, ligne de total. */
    public const GRIS_MUET = 'FFE9ECEF';

    // ── Textes ──────────────────────────────────────────────────────────────────────
    /** `--text-dark` #212529 — texte principal. */
    public const TEXTE = 'FF212529';

    /** `--text-body` #495057 — texte de cellule, sous-lignes. */
    public const TEXTE_CORPS = 'FF495057';

    /** `--text-muted` #6c757d — texte secondaire, sous-titres. */
    public const TEXTE_MUET = 'FF6C757D';

    // ── Bordures ────────────────────────────────────────────────────────────────────
    /** `--border-light` #dee2e6 — la bordure standard. */
    public const BORDURE = 'FFDEE2E6';

    // ── États sémantiques ───────────────────────────────────────────────────────────
    /**
     * `--danger` #dc3545 — et UNIQUEMENT pour du sémantique.
     *
     * ⚠ ICI : UN MONTANT NÉGATIF, jamais une décoration (règle 3 de la charte). Et jamais
     * seule : le signe « − » reste écrit dans la cellule. Une information portée par la
     * seule couleur disparaît à l'impression en noir et blanc, et pour qui ne distingue
     * pas le rouge du gris (WCAG 1.4.1).
     */
    public const DANGER = 'FFDC3545';

    /** `--warning-bg` #fff3cd — le bandeau d'avertissement du dictionnaire. */
    public const AVERTISSEMENT_FOND = 'FFFFF3CD';

    /** `--warning-dark` #664d03 — le SEUL texte admis sur `#fff3cd`. */
    public const AVERTISSEMENT_TEXTE = 'FF664D03';
}
