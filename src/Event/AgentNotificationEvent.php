<?php

namespace App\Event;

/**
 * @file Événement de notification interne destiné aux agents JS Brokers.
 * @description Émis aux moments métier clés (création/édition/suppression d'un
 * compte ou d'une entreprise) afin que toute l'équipe soit informée et puisse
 * agir au bon endroit. Un événement unique, paramétré par action/type, évite la
 * multiplication de classes (DRY). Le paiement de tokens réutilise l'événement
 * dédié TokenPurchaseEvent — il n'est donc pas couvert ici.
 */
class AgentNotificationEvent
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';

    public const TYPE_UTILISATEUR = 'utilisateur';
    public const TYPE_ENTREPRISE = 'entreprise';

    /**
     * @param string                $action  ACTION_* (create|update|delete)
     * @param string                $type    TYPE_* (utilisateur|entreprise)
     * @param string                $libelle Libellé lisible de l'entité concernée
     * @param array<string, string> $details Paires clé/valeur affichées dans l'e-mail
     */
    public function __construct(
        public readonly string $action,
        public readonly string $type,
        public readonly string $libelle,
        public readonly array $details = [],
    ) {
    }
}
