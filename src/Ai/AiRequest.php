<?php

namespace App\Ai;

use App\Ai\Comprehension\DemandeComprise;
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
     * @param list<array{mimeType: string, donneesBase64: string, nom: string}> $piecesNatives Fichiers attachés
     *        à transmettre NATIVEMENT au moteur multimodal (PDF scannés, images) : le modèle les lit par vision,
     *        sans dépendre d'une extraction texte. Ignoré par le moteur simulé.
     */
    public function __construct(
        public readonly array $systemContext,
        public readonly array $messages,
        public readonly AiScope $scope,
        public readonly array $piecesNatives = [],
        // Ce que la phase de compréhension a établi, quand elle a eu lieu. null =
        // le moteur n'en fait pas usage (moteur simulé, Claude) ou le comprenant
        // s'est replié : la planification retombe alors sur la règle « comprendre
        // avant d'agir » du prompt, exactement comme avant.
        public readonly ?DemandeComprise $comprise = null,
    ) {
    }

    /**
     * La même requête, augmentée de ce qui a été compris.
     *
     * Une nouvelle instance plutôt qu'une mutation : la requête est relue par la
     * télémétrie et par les deux prompts, et une valeur qui change en cours de
     * route rendrait le journal impossible à interpréter.
     */
    public function withComprehension(DemandeComprise $comprise): self
    {
        return new self($this->systemContext, $this->messages, $this->scope, $this->piecesNatives, $comprise);
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
