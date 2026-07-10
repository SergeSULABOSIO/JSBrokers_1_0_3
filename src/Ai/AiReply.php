<?php

namespace App\Ai;

/**
 * Réponse normalisée du moteur IA. `refused` = la question sortait du périmètre
 * d'accès de l'invité ; `toolUsed` = outil de données exécuté (traçabilité).
 */
final class AiReply
{
    public function __construct(
        public readonly string $content,
        public readonly bool $refused = false,
        public readonly ?string $toolUsed = null,
    ) {
    }
}
