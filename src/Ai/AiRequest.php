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
        /**
         * Le tour a-t-il produit une DÉCISION qui attend encore l'utilisateur — un plan
         * d'écriture, un document à produire ?
         *
         * Renseigné entre la planification et la rédaction, par le moteur qui vient de
         * voir les outils s'exécuter. C'est la différence entre « voici ce qui a été
         * fait » et « voici ce qui sera fait si vous validez », et la phase de rédaction
         * n'a aucun autre moyen de la connaître : elle ne voit que le fil.
         */
        public readonly bool $decisionEnAttente = false,
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
        return new self($this->systemContext, $this->messages, $this->scope, $this->piecesNatives, $comprise, $this->decisionEnAttente);
    }

    /**
     * La même requête, sachant qu'une décision attend l'utilisateur.
     *
     * Posée par le moteur AVANT la rédaction, quand la planification a produit une barre
     * de validation. Sans elle, la rédaction croit — parce que son prompt le lui dit —
     * que le travail est déjà fait, et l'annonce au PASSÉ : « le document a été
     * correctement rattaché au client », sous un bouton « Valider et exécuter » que
     * personne n'a encore touché.
     */
    public function withDecisionEnAttente(bool $enAttente = true): self
    {
        return new self($this->systemContext, $this->messages, $this->scope, $this->piecesNatives, $this->comprise, $enAttente);
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
