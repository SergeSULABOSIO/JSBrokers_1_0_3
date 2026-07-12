<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;

/**
 * Outil d'ACTION UI : propose la FERMETURE de l'espace de travail (retour à la
 * page personnelle de l'utilisateur, bouton « Fermer » de la colonne 1).
 * L'assistant n'exécute JAMAIS la déconnexion lui-même : la directive déclenche
 * la boîte de dialogue de confirmation standard du workspace — c'est
 * l'utilisateur qui valide (ou annule) manuellement, exactement comme s'il
 * avait cliqué sur le bouton. Aucune garde de périmètre : quitter son propre
 * espace de travail est un droit de tout utilisateur connecté.
 */
final class QuitterWorkspaceTool implements AiToolInterface
{
    public function name(): string
    {
        return 'quitter_workspace';
    }

    public function description(): string
    {
        return "Propose de FERMER l'espace de travail (déconnexion et retour à la page personnelle "
            . 'de l\'utilisateur). Une boîte de confirmation est TOUJOURS soumise à l\'utilisateur, '
            . 'qui valide ou annule lui-même — rien n\'est exécuté sans son accord. À appeler quand '
            . 'l\'utilisateur demande de fermer/quitter l\'espace de travail ou de se déconnecter.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    /** Chemin simulé : verbe de sortie + espace de travail / session. */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (preg_match('/\b(ferme[rsz]?|quitte[rsz]?|deconnecte[rsz]?|sortir|sors)\b/', $normalized)
            && preg_match('/\b(espace de travail|workspace|session)\b/', $normalized)) {
            return [];
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        return AiToolResult::ok(
            [
                'note' => 'La demande de fermeture est présentée à l\'utilisateur : une boîte de '
                    . 'confirmation s\'ouvre, il valide ou annule lui-même.',
            ],
            uiAction: ['type' => 'close-workspace'],
        );
    }
}
