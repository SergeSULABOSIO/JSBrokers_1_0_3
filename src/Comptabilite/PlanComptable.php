<?php

namespace App\Comptabilite;

use App\Entity\Charge;

/**
 * @file Référentiel des comptes du plan comptable SYSCOHADA utilisés par JS Brokers.
 * @description Source unique des codes et libellés de comptes mobilisés par la
 * génération des documents comptables (journal, grand livre, balance, résultat,
 * TFR, bilan, TFT). Les comptes de la classe 6 (charges) reprennent les libellés
 * de Charge::COMPTES_OHADA pour éviter toute duplication (DRY). Pas d'état :
 * uniquement des constantes et des helpers de résolution.
 */
final class PlanComptable
{
    // --- Comptes utilisés par la génération des écritures. ---
    public const CAPITAL_SOCIAL      = '101'; // Capitaux propres : apport des actionnaires
    public const REPORT_A_NOUVEAU    = '11';  // Résultats cumulés des exercices antérieurs
    public const RESULTAT_EXERCICE   = '13';  // Résultat net de l'exercice
    public const FOURNISSEURS        = '401'; // Dettes fournisseurs (charges engagées non payées)
    public const TVA_FACTUREE        = '443'; // État, TVA facturée (collectée sur les ventes)
    public const TVA_RECUPERABLE     = '445'; // État, TVA récupérable (déductible sur les achats)
    public const TVA_DUE             = '4441'; // État, TVA due (liquidée, restant à reverser)
    public const BANQUES             = '521'; // Trésorerie : banque
    public const CAISSE              = '571'; // Trésorerie : caisse (espèces)
    public const SERVICES_VENDUS     = '706'; // Produits : prestations de services vendues

    // --- Subdivisions employées par la comptabilité du COURTIER (workspace). ---
    public const RETRO_COMMISSIONS   = '632'; // Rémunérations d'intermédiaires (rétro-commissions)
    public const IMPOTS_TAXES        = '641'; // Impôts et taxes (taxes dont le courtier est redevable)

    /** @var array<string, string> Libellés des comptes hors classe 6 (+ subdivisions courtier). */
    private const LIBELLES = [
        self::CAPITAL_SOCIAL    => 'Capital social',
        self::REPORT_A_NOUVEAU  => 'Report à nouveau',
        self::RESULTAT_EXERCICE => 'Résultat net de l\'exercice',
        self::FOURNISSEURS      => 'Fournisseurs',
        self::TVA_FACTUREE      => 'État, TVA facturée',
        self::TVA_RECUPERABLE   => 'État, TVA récupérable',
        self::TVA_DUE           => 'État, TVA due',
        self::BANQUES           => 'Banques',
        self::CAISSE            => 'Caisse',
        self::SERVICES_VENDUS   => 'Services vendus',
        self::RETRO_COMMISSIONS => 'Rémunérations d\'intermédiaires et de conseils',
        self::IMPOTS_TAXES      => 'Impôts et taxes',
    ];

    /** Comptes de trésorerie (classe 5) suivis par le tableau de flux. */
    public const COMPTES_TRESORERIE = [self::BANQUES, self::CAISSE];

    /**
     * Classe comptable d'un compte = premier chiffre du code (1 à 7).
     * Ex. « 706 » → 7 (produits), « 62 » → 6 (charges), « 521 » → 5 (trésorerie).
     */
    public static function classe(string $compte): int
    {
        return (int) substr($compte, 0, 1);
    }

    /**
     * Libellé d'un compte. Les comptes de la classe 6 (charges) sont résolus depuis
     * le référentiel OHADA de Charge ; les autres depuis la table locale.
     */
    public static function libelle(string $compte): string
    {
        return self::LIBELLES[$compte]
            ?? Charge::COMPTES_OHADA[$compte]
            ?? $compte;
    }
}
