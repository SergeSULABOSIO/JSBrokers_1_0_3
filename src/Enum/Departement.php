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

    /** Courte description du rôle du département (badge / résumé d'une ligne). */
    public function description(): string
    {
        return match ($this) {
            self::DIRECTION       => 'Pilotage global et supervision de tous les départements.',
            self::FINANCE         => 'Comptabilité, dépenses, fiscalité, ventes et tarification.',
            self::COMMERCIAL      => 'Développement commercial, campagnes marketing et pipeline.',
            self::RELATION_CLIENT => 'Relation client, customer success et support / tickets.',
            self::RH              => 'Collaborateurs, départements, rôles et évaluations.',
        };
    }

    /**
     * Description détaillée du département (mission, périmètre, finalité), en
     * 3 paragraphes maximum. Affichée sur la page Départements et rappelée dans
     * le bandeau du tableau de bord du collaborateur.
     *
     * @return string[]
     */
    public function descriptionDetaillee(): array
    {
        return match ($this) {
            self::DIRECTION => [
                'La Direction Générale porte la vision et la stratégie de croissance de JS Brokers en tant qu\'éditeur SaaS. Elle fixe les objectifs d\'entreprise (OKR), arbitre la feuille de route produit et l\'allocation des ressources, et assure l\'alignement transverse entre les fonctions Produit, Revenu, Finance et Opérations.',
                'Elle dispose d\'un accès complet à la console et pilote l\'entreprise par les indicateurs SaaS clés : revenu récurrent (MRR/ARR), croissance, acquisition, rétention et trésorerie, via les vues consolidées CFO et CEO. Aucune restriction de périmètre ne lui est appliquée.',
                'Sa finalité est de sécuriser une croissance rentable et durable : capter les signaux faibles, décider vite sur des données fiables, et entretenir une culture de la performance partagée par tous les départements.',
            ],
            self::FINANCE => [
                'Le département Finance & Comptabilité garantit la santé économique du modèle SaaS. Il assure la reconnaissance du revenu, le suivi du MRR/ARR, de la marge brute et du coût d\'acquisition (CAC), ainsi que la maîtrise des dépenses, du burn et de la trésorerie.',
                'Son périmètre couvre les ventes et la tarification (paquets de tokens, coupons), la fiscalité, les documents comptables conformes au référentiel OHADA et le pilotage financier (vue CFO).',
                'Sa finalité est de fiabiliser le chiffre, de protéger l\'unit economics (LTV/CAC, marge) et d\'éclairer les décisions d\'investissement et de pricing par des analyses rigoureuses.',
            ],
            self::COMMERCIAL => [
                'Le département Commercial & Marketing est le moteur de croissance (Growth/RevOps) de JS Brokers. Il génère la demande, structure le tunnel d\'acquisition et convertit les prospects en clients, dans une logique de revenu prévisible et reproductible.',
                'Son périmètre comprend les ventes, les coupons promotionnels, les campagnes de cycle de vie (onboarding, activation, réactivation, upsell), le pipeline commercial et la connaissance des clients et prospects.',
                'Sa finalité est d\'optimiser le couple CAC/conversion, d\'activer l\'expansion (upsell, cross-sell) et de maximiser la valeur vie client (LTV) sur l\'ensemble du parcours.',
            ],
            self::RELATION_CLIENT => [
                'Le département Support & Relation Client est responsable de l\'expérience et de la rétention. Dans un modèle d\'abonnement, la valeur se gagne dans la durée : il sécurise l\'adoption, la satisfaction (CSAT/NPS) et la fidélité des comptes.',
                'Il traite les tickets de support dans le respect des SLA, suit les notifications, pilote le customer success (onboarding, suivi de santé des comptes) et entretient la relation avec les clients et entreprises (pipeline, tâches, interactions).',
                'Sa finalité est de réduire le churn et de développer la rétention nette du revenu (NRR) : résoudre vite, anticiper les risques de départ et transformer chaque interaction en occasion de fidélisation.',
            ],
            self::RH => [
                'Le département Ressources Humaines & Administration structure l\'organisation interne (People Ops) de JS Brokers. Dans une entreprise IT où la compétence est la première ressource, il clarifie qui fait quoi en répartissant les collaborateurs par département et par rôle.',
                'Son périmètre couvre la gestion des collaborateurs, la définition des départements et des rôles d\'accès, et le suivi des évaluations : objectifs (SMART/OKR individuels) et mesure de la performance au fil des périodes.',
                'Sa finalité est d\'assurer la clarté des responsabilités, la montée en compétences et l\'équité de l\'évaluation — au service de l\'engagement des équipes, sans intervenir sur les comptes clients de la plateforme.',
            ],
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
                'console.reglement_taxe.', 'console.vente.', 'console.coupon.', 'console.plan.', 'console.crm.cfo',
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
                // RH = gestion des collaborateurs internes (pas les comptes
                // clients/utilisateurs/entreprises de la plateforme).
                'console.collaborateur.', 'console.departement.', 'console.evaluation.',
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
            self::RH              => ['Collaborateurs', 'Départements & rôles', 'Évaluations'],
        };
    }
}
