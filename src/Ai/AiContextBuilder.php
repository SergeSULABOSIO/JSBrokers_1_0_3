<?php

namespace App\Ai;

use App\Ai\Scope\AiScope;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\AssistantParametresRepository;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * Construit la requête normalisée adressée au moteur IA : nom du personnage
 * (paramètres de l'entreprise), périmètre d'accès de l'invité (source unique :
 * WorkspaceAccessResolver) et historique récent de la conversation.
 */
class AiContextBuilder
{
    /** Plafond d'historique transmis au moteur (maîtrise du contexte/coût). */
    private const MAX_MESSAGES = 20;

    public function __construct(
        private readonly AssistantParametresRepository $parametresRepository,
        private readonly WorkspaceAccessResolver $accessResolver,
    ) {
    }

    public function build(Entreprise $entreprise, Invite $invite, AssistantConversation $conversation): AiRequest
    {
        $messages = [];
        foreach ($conversation->getMessages() as $message) {
            $messages[] = [
                'role'    => $message->getRole() === AssistantMessage::ROLE_ASSISTANT ? 'assistant' : 'user',
                'content' => (string) $message->getContenu(),
            ];
        }
        $messages = array_slice($messages, -self::MAX_MESSAGES);

        return new AiRequest(
            systemContext: [
                'assistantNom'  => $this->parametresRepository->nomPour($entreprise),
                'entrepriseNom' => (string) $entreprise->getNom(),
                'perimetre'     => $this->accessResolver->describePerimetreDetailed($invite),
                'date'          => (new \DateTimeImmutable('now'))->format('Y-m-d'),
            ],
            messages: $messages,
            scope: new AiScope($entreprise, $invite),
        );
    }

    /**
     * Sérialisation texte du contexte système — inutilisée par le moteur simulé,
     * prête pour le message système du futur bridge LLM (Symfony AI).
     */
    public function toSystemPrompt(AiRequest $request): string
    {
        $ctx = $request->systemContext;
        $perimetre = json_encode($ctx['perimetre'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
        Tu es {$ctx['assistantNom']}, l'assistant IA de l'entreprise de courtage « {$ctx['entrepriseNom']} »
        sur la plateforme JS Brokers. Nous sommes le {$ctx['date']}.
        Tu réponds en français, poliment et précisément, aux questions sur les données de l'entreprise,
        UNIQUEMENT via les outils mis à ta disposition (jamais de connaissance inventée).
        Le périmètre d'accès de ton interlocuteur est strictement limité à :
        {$perimetre}
        Pour toute demande hors de ce périmètre, refuse poliment en expliquant tes limitations techniques
        liées aux droits d'accès, sans révéler la moindre donnée.
        PROMPT;
    }
}
