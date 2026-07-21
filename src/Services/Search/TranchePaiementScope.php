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

    /**
     * Fragment de critère à passer au moteur de recherche pour restreindre à un statut de
     * paiement. SOURCE UNIQUE partagée par les chips de la rubrique et les outils génériques
     * de l'assistant IA (compter_entites / rechercher_entites) : le même critère traverse la
     * même interception (filtrage/tri en mémoire par TranchePaiementService), donc Ket et la
     * barre de chips donnent EXACTEMENT le même résultat. Retourne un tableau vide si
     * l'entité n'est pas Tranche ou si le statut est absent/inconnu (filtre ignoré).
     *
     * @return array<string, array{operator: string, value: string, label: string}>
     */
    public static function critereRecherche(string $entityShortName, ?string $statut): array
    {
        if ($entityShortName !== 'Tranche' || !self::estValide($statut)) {
            return [];
        }

        return [self::CRITERION_KEY => [
            'operator' => '=',
            'value' => $statut,
            'label' => self::libelle($statut),
        ]];
    }

    /**
     * Détecte un statut de paiement dans une question en langage naturel déjà normalisée
     * (AiText::normalize : minuscules, sans accents). Sert au moteur simulé pour que
     * « combien de tranches impayées ? » applique le MÊME filtre que le chip correspondant.
     */
    public static function detecterDepuisTexte(string $texteNormalise): ?string
    {
        // Ordre volontaire : les statuts les plus spécifiques d'abord (« rétro à payer » et
        // « commission exigible » sont des flux distincts qui mentionnent aussi « payer »).
        if (preg_match('/\bretro(commissions?)?\b/', $texteNormalise)
            && preg_match('/\b(payer|verser|reverser|dues?|exigibles?)\b/', $texteNormalise)) {
            return self::STATUT_RETRO_A_PAYER;
        }
        if (preg_match('/\bcommissions?\b/', $texteNormalise)
            && preg_match('/\bexigibles?\b|\ba collecter\b/', $texteNormalise)) {
            return self::STATUT_COMMISSION_EXIGIBLE;
        }
        if (preg_match('/\bpartiellement\b/', $texteNormalise)) {
            return self::STATUT_PARTIELLEMENT;
        }
        if (preg_match('/\b(echues?|en retard)\b/', $texteNormalise)) {
            return self::STATUT_ECHUES;
        }
        if (preg_match('/\ba echoir\b/', $texteNormalise)) {
            return self::STATUT_A_ECHOIR;
        }
        if (preg_match('/\bpayees?\b|\bsoldees?\b/', $texteNormalise)) {
            return self::STATUT_PAYEES;
        }
        if (preg_match('/\bimpayees?\b|\bimpayes?\b|\barrieres?\b/', $texteNormalise)) {
            return self::STATUT_IMPAYEES;
        }

        return null;
    }
}
