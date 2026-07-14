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
use App\Services\JSBDynamicSearchService;

/**
 * Construit la requête normalisée adressée au moteur IA : nom du personnage
 * (paramètres de l'entreprise), périmètre d'accès de l'invité (source unique :
 * WorkspaceAccessResolver), historique récent de la conversation et fiches des
 * objets ATTACHÉS au contexte par l'utilisateur (re-validées à chaque envoi).
 */
class AiContextBuilder
{
    /** Plafond d'historique transmis au moteur (maîtrise du contexte/coût). */
    private const MAX_MESSAGES = 20;

    public function __construct(
        private readonly AssistantParametresRepository $parametresRepository,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly GuideRepository $guides,
        private readonly JSBDynamicSearchService $searchService,
        private readonly FicheNormaliseur $ficheNormaliseur,
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
                'objetsAttaches' => $this->objetsAttaches($conversation, $entreprise, $invite),
            ],
            messages: $messages,
            scope: new AiScope($entreprise, $invite),
        );
    }

    /**
     * Sérialisation texte du contexte système — inutilisée par le moteur simulé,
     * prête pour le message système du futur bridge LLM (Symfony AI).
     */
    /**
     * Fiches des objets attachés à la conversation, re-validées FAIL-CLOSED au
     * moment de l'envoi (whitelist + canRead selon le rôle + scoping
     * entreprise) : un objet supprimé ou devenu inaccessible est ignoré
     * silencieusement — la puce reste affichée côté chat, l'assistant dira
     * simplement qu'il ne trouve pas la donnée.
     * PUBLIC : également source des infobulles des puces de contexte du chat
     * (l'utilisateur voit EXACTEMENT ce que l'assistant capture).
     */
    public function objetsAttaches(AssistantConversation $conversation, Entreprise $entreprise, Invite $invite): array
    {
        $labels = $this->accessResolver->libellesEntites();
        $objets = [];
        foreach ($conversation->getContextes() as $contexte) {
            $type = (string) $contexte->getEntityType();
            $fqcn = 'App\\Entity\\' . $type;
            if (!isset($labels[$type]) || !class_exists($fqcn)
                || !$this->accessResolver->canRead($invite, $type)) {
                continue;
            }
            $result = $this->searchService->search($fqcn, ['id' => $contexte->getEntityId()], $entreprise, null, 1, 1);
            $entity = $result['data'][0] ?? null;
            if (($result['status']['code'] ?? 500) !== 200 || $entity === null) {
                continue;
            }
            $objets[] = [
                'type'    => $type,
                'libelle' => $labels[$type],
                'id'      => $contexte->getEntityId(),
                'nom'     => (string) $contexte->getLabel(),
                'fiche'   => $this->ficheNormaliseur->fiche($entity),
            ];
        }

        return $objets;
    }

    public function toSystemPrompt(AiRequest $request): string
    {
        $ctx = $request->systemContext;
        $perimetre = json_encode($ctx['perimetre'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $catalogue = $this->catalogueGuides();
        $sectionObjets = $this->sectionObjetsAttaches($ctx['objetsAttaches'] ?? []);

        return <<<PROMPT
        Tu es {$ctx['assistantNom']}, l'assistant IA de l'entreprise de courtage « {$ctx['entrepriseNom']} »
        sur la plateforme JS Brokers. Nous sommes le {$ctx['date']}.
        Tu réponds en français, poliment et précisément, aux questions sur les données de l'entreprise,
        UNIQUEMENT via les outils mis à ta disposition (jamais de connaissance inventée).
        Règles de conduite :
        - Appuie-toi sur tes outils : « lesquels / liste » => rechercher_entites ; « combien » =>
          compter_entites ; détail/attribut d'une fiche précise => lire_fiche ; chiffre métier
          CALCULÉ (prime, commission, sinistralité) d'un enregistrement => indicateur_calcule
          (entite=Entreprise pour les totaux du cabinet, période du/au possible) ; finances de
          L'ENTREPRISE (trésorerie, résultat, bilan, balance, TVA) => document_comptable ;
          répartitions/moyennes/sommes sur des champs STOCKÉS => statistiques ; « crée / ajoute /
          modifie une fiche » => ouvrir_dialogue (en édition, obtiens d'abord l'id via
          rechercher_entites) ; « ouvre la rubrique X » ou « ouvre le tableau de bord » =>
          ouvrir_rubrique (entite=TableauDeBord pour le tableau de bord) ; « visualise /
          affiche la fiche X à l'écran » => visualiser_fiche ; « ferme / quitte l'espace de
          travail » => quitter_workspace (une confirmation manuelle est toujours demandée).
          Tu n'écris jamais toi-même : le formulaire s'ouvre et l'utilisateur le complète et
          l'enregistre.
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
        liées aux droits d'accès, sans révéler la moindre donnée.{$sectionObjets}
        PROMPT;
    }

    /**
     * Section du prompt système consacrée aux objets ATTACHÉS par l'utilisateur
     * (déjà re-validés par objetsAttaches()) ; chaîne vide sans objet — le
     * prompt reste alors strictement identique (non-régression).
     */
    private function sectionObjetsAttaches(array $objets): string
    {
        if ($objets === []) {
            return '';
        }

        return "\nL'utilisateur a ATTACHÉ les fiches suivantes au contexte de cette conversation : elles sont"
            . "\ndéjà vérifiées et dans son périmètre. Appuie-toi dessus EN PRIORITÉ quand il y fait référence,"
            . "\nsans re-lire la fiche via un outil. ATTENTION : chaque fiche ne contient QUE les attributs"
            . "\nSTOCKÉS de l'enregistrement — JAMAIS ses enregistrements liés (tâches, documents, avenants,"
            . "\ncotations…) ni ses indicateurs calculés. Ne conclus donc JAMAIS à l'absence d'éléments liés"
            . "\nà partir d'une fiche : cherche-les avec rechercher_entites et son paramètre lieA, qui suit"
            . "\nles relations à plusieurs niveaux (ex. tâches de la piste 42 : entite=Tache,"
            . "\nlieA={entite: \"Piste\", id: 42} ; tâches du client 82 via ses pistes : entite=Tache,"
            . "\nlieA={entite: \"Client\", id: 82}) ; un chiffre calculé se lit via indicateur_calcule :\n"
            . json_encode($objets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
