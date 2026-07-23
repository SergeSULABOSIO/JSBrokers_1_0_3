<?php

namespace App\Ai\Mutation;

use App\Entity\Invite;

/**
 * Périmètre des entités que l'assistant IA (Ket) est autorisé à ÉCRIRE ou
 * SUPPRIMER.
 *
 * Politique (validée par le propriétaire, 2026-07-23) : PARITÉ LECTURE/ÉCRITURE —
 * Ket peut muter TOUTE entité interrogeable de l'espace de travail (toute entrée
 * de WorkspaceAccessResolver::MAP ayant une classe Doctrine), paramétrage et
 * référentiels inclus. Restent hors périmètre les seules PSEUDO-entités sans
 * classe (DocumentComptable, AssistantIa) et la GESTION des rôles/invités (Invite,
 * RolesEn*, AssistantParametres) — cf. WorkspaceAccessResolver::isRoleManagementEntity,
 * ceinture + bretelles. Fail-closed : une entité absente d'ici n'est jamais mutable.
 *
 * L'accès effectif reste gouverné par les rôles (le resolver) : figurer ici lève
 * l'interdit d'écriture propre à Ket, cela n'accorde aucun droit par soi-même.
 */
final class MutationAllowlist
{
    /**
     * Noms courts d'entités ouvertes à l'écriture/suppression par Ket = l'ensemble
     * des entités interrogeables (parité avec la lecture).
     *
     * Garde-fou d'extension (à re-vérifier pour toute NOUVELLE entité de la carte
     * d'accès) : n'ajouter qu'une entité (a) dotée d'un FormType App\Form\{Nom}Type
     * et (b) dont aucun setter ManyToOne ne maintient de collection inverse en
     * cascade-persist — sinon la validation FormType du dry-run
     * (WorkspaceMutationService::analyserOperation) pourrait rattacher l'entité de
     * test à un parent géré et la persister au flush suivant. Les 31 entités
     * ci-dessous ont été vérifiées conformes ; de plus le chemin de dry-run
     * (PreparerOperationsTool) ne flush jamais — double garde.
     */
    public const MEMBRES = [
        // Production
        'Client',
        'Cotation',       // « Propositions »
        'Avenant',
        'Piste',
        'Portefeuille',
        'Assureur',
        'Risque',
        'Partenaire',     // « Intermédiaires »
        'Groupe',
        'Contact',
        // Finances
        'Note',
        'DepenseCourtier', // « Dépenses »
        'ChargeCourtier',  // « Charges »
        'Paiement',
        'Bordereau',
        'Tranche',
        'RevenuPourCourtier', // « Revenus »
        'Chargement',      // « Types Chargements »
        'TypeRevenu',
        'Taxe',
        'Monnaie',
        'CompteBancaire',
        'Fournisseur',
        // Marketing
        'Tache',
        'Feedback',
        // Sinistre
        'PieceSinistre',
        'NotificationSinistre',
        'OffreIndemnisationSinistre', // « Règlements »
        'ModelePieceSinistre',        // « Types pièces »
        // Administration
        'Document',
        'Classeur',
    ];

    /** Niveaux de mutation gouvernés (lecture exclue : ce n'est pas une mutation). */
    public const NIVEAUX_MUTATION = [
        Invite::ACCESS_ECRITURE,
        Invite::ACCESS_MODIFICATION,
        Invite::ACCESS_SUPPRESSION,
    ];

    public static function autorise(string $entityShortName): bool
    {
        return in_array($entityShortName, self::MEMBRES, true);
    }

    /** @return string[] Copie de la liste (pour alimenter un enum de schéma). */
    public static function membres(): array
    {
        return self::MEMBRES;
    }
}
