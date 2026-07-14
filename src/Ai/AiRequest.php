<?php

namespace App\Ai;

use App\Ai\Scope\AiScope;

/**
 * Requête normalisée adressée au moteur IA : contexte système structuré
 * (nom du personnage, entreprise, périmètre d'accès), historique de la
 * conversation et scope de sécurité. Format indépendant du moteur : le
 * simulateur la consomme telle quelle, un futur bridge Symfony AI la mappera
 * vers MessageBag + message système.
 */
final class AiRequest
{
    /**
     * @param array{assistantNom: string, entrepriseNom: string, perimetre: array, date: string, objetsAttaches: array} $systemContext
     * @param list<array{role: string, content: string}> $messages Historique, du plus ancien au plus récent.
     */
    public function __construct(
        public readonly array $systemContext,
        public readonly array $messages,
        public readonly AiScope $scope,
    ) {
    }

    /** Dernier message de l'utilisateur (celui auquel le moteur doit répondre). */
    public function lastUserMessage(): string
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if (($this->messages[$i]['role'] ?? null) === 'user') {
                return (string) $this->messages[$i]['content'];
            }
        }

        return '';
    }
}
