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
            $contenu = (string) $message->getContenu();
            // Chaque message utilisateur « transporte » son instantané de contexte :
            // l'annotation lève l'ambiguïté temporelle pour le moteur (un message
            // ancien portait peut-être sur un objet depuis remplacé — l'historique
            // le dit désormais explicitement, la liste ACTUELLE restant la seule
            // source des SUJETS PRINCIPAUX via le prompt système).
            if ($message->getRole() === AssistantMessage::ROLE_USER
                && ($objets = $message->getContexteObjets()) !== null) {
                $contenu = $this->marqueurContexte($objets) . "\n" . $contenu;
            }
            $messages[] = [
                'role'    => $message->getRole() === AssistantMessage::ROLE_ASSISTANT ? 'assistant' : 'user',
                'content' => $contenu,
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
          travail » => quitter_workspace (une confirmation manuelle est toujours demandée) ;
          solde de tokens / crédits restants / consommation de tokens => solde_tokens
          (restitue TOUJOURS le rappel de la logique de consommation fourni par l'outil,
          en texte simple).
          Tu n'écris jamais toi-même : le formulaire s'ouvre et l'utilisateur le complète et
          l'enregistre.
        - Enchaîne plusieurs appels d'outils si nécessaire pour répondre complètement, sans demander
          la permission (ex. lister des clients puis lire un indicateur pour chacun).
        - Ne réponds JAMAIS que tu manques d'outil sans avoir examiné la liste des outils disponibles ;
          si aucun ne convient vraiment, dis précisément ce que tu sais faire à la place.
        - Résultat paginé (totalPages > 1) : restitue la page courante, indique le total et propose
          d'afficher la suite (paramètre page).
        - Mets en forme tes réponses avec un Markdown simple et sobre quand cela aide à la
          lisibilité : listes à puces ou numérotées, **gras** pour les points clés, tableaux
          Markdown standard pour des données tabulaires (colonnes courtes, 4-5 maximum). Au plus
          un niveau de titre (##), réservé aux réponses longues qui gagnent à être structurées —
          jamais dans une réponse courte. Pas de bloc de code sauf si le contenu EST réellement du
          code. Pour signaler un statut ou une information qualifiée, utilise EXCLUSIVEMENT la
          syntaxe de lien Markdown standard avec un de ces cinq mots-clés réservés comme cible :
          [Payée](#success), [En retard](#danger), [À surveiller](#warning), [Info](#info),
          [Aucun impayé](#neutral). N'utilise jamais d'autre cible de lien (URL, ancre libre) :
          aucun lien cliquable n'existe dans cette interface — seuls ces cinq mots-clés sont
          interprétés. Reste sobre : la mise en forme sert la lisibilité, jamais la décoration.
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

        return "\nSUJETS PRINCIPAUX — l'utilisateur a ATTACHÉ les fiches ci-dessous au contexte de cette"
            . "\nconversation. RÈGLE IMPÉRATIVE : ces objets sont les SUJETS PRINCIPAUX de la conversation."
            . "\nAvant CHAQUE réponse, relis cette liste et recentre ton raisonnement dessus : interprète toute"
            . "\nquestion — même formulée sans les nommer (« quel est le solde ? », « et ses tâches ? »,"
            . "\n« ce client ») — comme portant sur ces objets, sauf si l'utilisateur désigne EXPLICITEMENT"
            . "\nautre chose. Cible tes appels d'outils sur eux : leurs id alimentent lieA, id/cible,"
            . "\ntrancheId, etc. — jamais un autre enregistrement par défaut."
            . "\nCette liste reflète l'état ACTUEL du contexte et PRÉVAUT sur l'historique de la conversation :"
            . "\nsi un objet a été ajouté, remplacé ou retiré depuis les messages précédents, ajuste-toi"
            . "\nimmédiatement à la liste ci-dessous — ne reste jamais sur un objet qui n'y figure plus."
            . "\nLes fiches sont déjà vérifiées et dans le périmètre de l'utilisateur : appuie-toi dessus"
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
     * Marqueur compact préfixé aux messages utilisateur de l'historique : les
     * objets qui étaient en contexte À L'ENVOI de ce message (type #id — nom).
     * Libellés seulement — les fiches complètes ne concernent que la liste
     * ACTUELLE (section SUJETS PRINCIPAUX du prompt système).
     *
     * @param array<int, array{type: string, id: int, nom: string}> $objets
     */
    private function marqueurContexte(array $objets): string
    {
        $items = array_map(
            static fn (array $o) => sprintf('%s #%d — %s', $o['type'] ?? '?', (int) ($o['id'] ?? 0), $o['nom'] ?? ''),
            $objets,
        );

        return '[Objets en contexte à l\'envoi de ce message : ' . implode(' ; ', $items) . ']';
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
