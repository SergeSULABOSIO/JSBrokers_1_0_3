<?php

namespace App\Enum;

/**
 * @file Départements internes JS Brokers (organisation des collaborateurs).
 * @description Chaque département regroupe — sans en retirer aucune — les rubriques
 * fonctionnelles existantes de la Console et déclare le périmètre d'accès
 * correspondant (préfixes de noms de routes `console.*`). La Direction Générale a
 * un accès complet. Source de vérité unique utilisée par le contrôle d'accès
 * (ConsoleAccessResolver), la navigation filtrée et les e-mails d'affectation.
 */
enum Departement: string
{
    case DIRECTION       = 'direction';
    case FINANCE         = 'finance';
    case COMMERCIAL      = 'commercial';
    case RELATION_CLIENT = 'relation_client';
    case RH              = 'rh';

    /** Libellé humain affiché partout (listes, formulaire, e-mail). */
    public function label(): string
    {
        return match ($this) {
            self::DIRECTION       => 'Direction Générale',
            self::FINANCE         => 'Finance & Comptabilité',
            self::COMMERCIAL      => 'Commercial & Marketing',
            self::RELATION_CLIENT => 'Support & Relation Client',
            self::RH              => 'Ressources Humaines & Administration',
        };
    }

    /** Alias d'icône (IconCanvasProvider) représentant le département. */
    public function icon(): string
    {
        return match ($this) {
            self::DIRECTION       => 'dashboard',
            self::FINANCE         => 'charge',
            self::COMMERCIAL      => 'operation',
            self::RELATION_CLIENT => 'client',
            self::RH              => 'action:role',
        };
    }

    /** Courte description du rôle du département (entête de panneau / e-mail). */
    public function description(): string
    {
        return match ($this) {
            self::DIRECTION       => 'Pilotage global et supervision de tous les départements.',
            self::FINANCE         => 'Comptabilité, dépenses, fiscalité, ventes et tarification.',
            self::COMMERCIAL      => 'Développement commercial, campagnes marketing et pipeline.',
            self::RELATION_CLIENT => 'Relation client, customer success et support / tickets.',
            self::RH              => 'Collaborateurs, comptes, départements et évaluations.',
        };
    }

    /** La Direction Générale accède à toute la Console. */
    public function grantsAll(): bool
    {
        return $this === self::DIRECTION;
    }

    /**
     * Préfixes de noms de routes `console.*` accessibles à ce département.
     * Le tableau de bord (`console.dashboard`) reste accessible à tous.
     *
     * @return string[]
     */
    public function routePrefixes(): array
    {
        return match ($this) {
            self::DIRECTION => ['console.'],
            self::FINANCE => [
                'console.depense.', 'console.charge.', 'console.document.', 'console.taxe.',
                'console.vente.', 'console.coupon.', 'console.plan.', 'console.crm.cfo',
            ],
            self::COMMERCIAL => [
                'console.vente.', 'console.coupon.',
                'console.crm.campagne.', 'console.crm.pipeline.', 'console.crm.client.',
            ],
            self::RELATION_CLIENT => [
                'console.crm.dashboard', 'console.crm.client.', 'console.crm.cs',
                'console.crm.entreprise.', 'console.crm.tache.', 'console.crm.pipeline.',
                'console.crm.ticket.', 'console.crm.notification.',
            ],
            self::RH => [
                'console.collaborateur.', 'console.utilisateur.', 'console.client.',
                'console.entreprise.', 'console.departement.', 'console.evaluation.',
            ],
        };
    }

    /**
     * Libellés humains des rubriques couvertes (affichage / e-mail d'affectation).
     *
     * @return string[]
     */
    public function rubriques(): array
    {
        return match ($this) {
            self::DIRECTION       => ['Toutes les rubriques de la console'],
            self::FINANCE         => ['Dépenses', 'Charges', 'Documents comptables', 'Fiscalité', 'Ventes', 'Coupons', 'Plan tarifaire', 'CFO'],
            self::COMMERCIAL      => ['Ventes', 'Coupons', 'Marketing', 'Pipeline', 'Clients et prospects'],
            self::RELATION_CLIENT => ['Tableau de bord CRM', 'Clients', 'Customer Success', 'Entreprises', 'Tâches', 'Pipeline', 'Support Client', 'Notifications'],
            self::RH              => ['Collaborateurs', 'Utilisateurs', 'Clients', 'Entreprises', 'Départements & rôles', 'Évaluations'],
        };
    }
}
