<?php

namespace App\Ai;

/**
 * Réponse normalisée du moteur IA. `refused` = la question sortait du périmètre
 * d'accès de l'invité ; `toolUsed` = outil de données exécuté (traçabilité) ;
 * `actions` = directives d'intention UI (AiToolResult::uiAction) que le chat
 * traduit sur le bus d'événements du workspace (ex. ouverture de dialogue).
 */
final class AiReply
{
    public function __construct(
        public readonly string $content,
        public readonly bool $refused = false,
        public readonly ?string $toolUsed = null,
        public readonly array $actions = [],
    ) {
    }
}
