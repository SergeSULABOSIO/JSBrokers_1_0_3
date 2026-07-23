<?php

namespace App\Ai\Mutation;

use App\Entity\Invite;

/**
 * Périmètre des entités que l'assistant IA (Ket) est autorisé à ÉCRIRE ou
 * SUPPRIMER — volontairement DISTINCT de la carte complète de
 * WorkspaceAccessResolver.
 *
 * Politique (validée) : Ket n'agit QUE sur des DONNÉES MÉTIER de l'utilisateur,
 * dans les strictes limites des rôles en vigueur. Les entités de PARAMÉTRAGE /
 * CONFIGURATION de l'espace de travail (référentiels : monnaies, taxes, types,
 * modèles…) et de GESTION des rôles/invités (Invite, RolesEn*, AssistantParametres)
 * sont EXCLUES d'office — même pour le propriétaire. Fail-closed : une entité qui
 * ne figure pas ici n'est jamais mutable par Ket.
 *
 * Démarrage sur un sous-ensemble à faible surface de risque ; étendre = ajouter
 * un nom court métier à MEMBRES (les gardes d'accès restent celles du resolver).
 */
final class MutationAllowlist
{
    /**
     * Noms courts d'entités MÉTIER ouvertes à l'écriture/suppression par Ket.
     * Ne JAMAIS y ajouter une entité de paramétrage/référentiel (Monnaie, Taxe,
     * TypeRevenu, ModelePieceSinistre…) ni de rôles/invités.
     *
     * Garde-fou d'extension : n'ajouter qu'une entité dont les setters ManyToOne
     * ne maintiennent PAS de collection inverse en cascade-persist — sinon la
     * validation FormType du dry-run (WorkspaceMutationService::analyserOperation)
     * pourrait rattacher l'entité de test à un parent géré et la persister au
     * flush suivant. Toutes les entités ci-dessous ont été vérifiées conformes :
     * aucun de leurs setters ManyToOne ne maintient de collection inverse, et le
     * chemin de dry-run (PreparerOperationsTool) ne flush jamais — double garde.
     *
     * Chaque entité dispose d'un FormType App\Form\{Nom}Type et d'une entrée dans
     * WorkspaceAccessResolver::MAP (l'accès reste gouverné par les rôles ; figurer
     * ici n'accorde aucun droit, cela lève seulement l'interdit d'écriture de Ket).
     */
    public const MEMBRES = [
        // Production
        'Client',
        'Piste',
        'Avenant',
        'Cotation',       // « Propositions »
        'Portefeuille',
        'Assureur',
        'Risque',
        'Partenaire',     // « Intermédiaires »
        'Groupe',
        // Finances
        'Note',
        'DepenseCourtier', // « Dépenses »
        // Marketing
        'Tache',
        'Feedback',
        // Sinistre
        'PieceSinistre',
        'NotificationSinistre',
        'OffreIndemnisationSinistre', // « Règlements »
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
