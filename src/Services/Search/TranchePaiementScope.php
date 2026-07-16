<?php

namespace App\Services\Search;

/**
 * Périmètre « Statut de paiement » des tranches : critère synthétique porté par la barre
 * de recherche (badge + dialogue avancé + chips de la liste Tranches).
 *
 * Le statut de règlement d'une tranche (prime client ET commission) n'est pas stocké en
 * base : il est dérivé à la volée par TrancheIndicatorStrategy (prorata des encaissements
 * via Article → Note → Paiement). Ce critère est donc intercepté par le moteur de recherche
 * (JSBDynamicSearchService) qui bascule sur un filtrage/tri en mémoire assuré par
 * TranchePaiementService, au lieu du chemin SQL standard.
 */
final class TranchePaiementScope
{
    /**
     * Clé de critère synthétique. La valeur transmise est l'un des statuts de VALEURS.
     */
    public const CRITERION_KEY = '__statut_paiement__';

    public const STATUT_IMPAYEES = 'impayees';
    public const STATUT_ECHUES = 'echues';
    public const STATUT_A_ECHOIR = 'a_echoir';
    public const STATUT_PARTIELLEMENT = 'partiellement';
    public const STATUT_PAYEES = 'payees';
    public const STATUT_RETRO_A_PAYER = 'retro_a_payer';
    public const STATUT_COMMISSION_EXIGIBLE = 'commission_exigible';

    /**
     * @var array<string, string> Valeur du critère => libellé affiché (badge, select du
     *      dialogue avancé, chips). L'ordre est celui de présentation.
     *      « Rétro à payer » est un flux inverse (décaissement vers le partenaire) :
     *      il croise les statuts d'encaissement, d'où un filtre dédié.
     */
    public const VALEURS = [
        self::STATUT_IMPAYEES => 'Impayées',
        self::STATUT_ECHUES => 'Échues (en retard)',
        self::STATUT_A_ECHOIR => 'À échoir (impayées)',
        self::STATUT_PARTIELLEMENT => 'Partiellement payées',
        self::STATUT_PAYEES => 'Payées',
        self::STATUT_RETRO_A_PAYER => 'Rétro partenaire à payer',
        self::STATUT_COMMISSION_EXIGIBLE => 'Commission exigible',
    ];

    public static function estValide(?string $statut): bool
    {
        return $statut !== null && isset(self::VALEURS[$statut]);
    }

    public static function libelle(string $statut): string
    {
        return self::VALEURS[$statut] ?? $statut;
    }
}
