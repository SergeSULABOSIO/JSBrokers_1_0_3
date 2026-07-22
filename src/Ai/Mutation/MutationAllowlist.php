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
     * Ne JAMAIS y ajouter une entité de paramétrage/référentiel ni de rôles.
     *
     * Garde-fou d'extension : n'ajouter qu'une entité dont les setters ManyToOne
     * ne maintiennent PAS de collection inverse en cascade-persist — sinon la
     * validation FormType du dry-run (WorkspaceMutationService::analyserOperation)
     * pourrait rattacher l'entité de test à un parent géré et la persister au
     * flush suivant. Les 5 entités ci-dessous ont été vérifiées conformes.
     */
    public const MEMBRES = [
        'Client',
        'Tache',
        'Note',
        'Piste',
        'Avenant',
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
