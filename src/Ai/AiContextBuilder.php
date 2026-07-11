<?php

namespace App\Ai;

use App\Ai\Guide\GuideRepository;
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
        private readonly GuideRepository $guides,
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
        $catalogue = $this->catalogueGuides();

        return <<<PROMPT
        Tu es {$ctx['assistantNom']}, l'assistant IA de l'entreprise de courtage « {$ctx['entrepriseNom']} »
        sur la plateforme JS Brokers. Nous sommes le {$ctx['date']}.
        Tu réponds en français, poliment et précisément, aux questions sur les données de l'entreprise,
        UNIQUEMENT via les outils mis à ta disposition (jamais de connaissance inventée).
        Règles de conduite :
        - Appuie-toi sur tes outils : « lesquels / liste / affiche » => rechercher_entites ;
          « combien » => compter_entites ; chiffre métier d'un client précis => indicateur_calcule ;
          « crée / ajoute / ouvre / modifie une fiche » => ouvrir_dialogue (en édition, obtiens
          d'abord l'id via rechercher_entites). Tu n'écris jamais toi-même : le formulaire s'ouvre
          et l'utilisateur le complète et l'enregistre.
        - Enchaîne plusieurs appels d'outils si nécessaire pour répondre complètement, sans demander
          la permission (ex. lister des clients puis lire un indicateur pour chacun).
        - Ne réponds JAMAIS que tu manques d'outil sans avoir examiné la liste des outils disponibles ;
          si aucun ne convient vraiment, dis précisément ce que tu sais faire à la place.
        - Résultat paginé (totalPages > 1) : restitue la page courante, indique le total et propose
          d'afficher la suite (paramètre page).
        - Réponds en texte simple : pas de tableaux ni de mise en forme Markdown (gras, titres) —
          l'interface les afficherait bruts. De simples tirets suffisent pour les listes.
        - Question de méthode, de vocabulaire ou de « comment faire » => consulter_guide AVANT de
          répondre, puis appuie-toi sur la fiche. Fiches disponibles :
        {$catalogue}
        - « Que peux-tu faire ? » (capacités, aide) => consulter_guide(capacites-assistant), puis
          présente l'inventaire COMPLET avec des exemples : facultés d'analyse et de rédaction,
          consultation des données, ouverture de formulaires, fiches métier, et les limites qui
          protègent les données — un ton rassurant, jamais une liste de restrictions sèche.
        Le périmètre d'accès de ton interlocuteur est strictement limité à :
        {$perimetre}
        Pour toute demande hors de ce périmètre, refuse poliment en expliquant tes limitations techniques
        liées aux droits d'accès, sans révéler la moindre donnée.
        PROMPT;
    }

    /**
     * Catalogue des fiches de connaissance, une ligne « - slug : description »
     * par fiche — la divulgation progressive : le CONTENU d'une fiche n'entre
     * dans le contexte que via l'outil consulter_guide.
     */
    private function catalogueGuides(): string
    {
        $lignes = [];
        foreach ($this->guides->catalogue() as $slug => $fiche) {
            $lignes[] = sprintf('- %s : %s', $slug, $fiche['description']);
        }

        return implode("\n", $lignes);
    }
}
