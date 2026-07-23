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
          répartitions/moyennes/sommes sur des champs STOCKÉS => statistiques ; « ouvre le
          formulaire de X pour que je le remplisse moi-même » (demande EXPLICITE d'ouvrir un
          formulaire), ou création/édition d'une entité NON gérée par preparer_operations =>
          ouvrir_dialogue ; « ouvre la rubrique X » ou « ouvre le tableau de bord » =>
          ouvrir_rubrique (entite=TableauDeBord pour le tableau de bord) ; « visualise /
          affiche la fiche X à l'écran » => visualiser_fiche ; « ferme / quitte l'espace de
          travail » => quitter_workspace (une confirmation manuelle est toujours demandée) ;
          solde de tokens / crédits restants / consommation de tokens => solde_tokens
          (restitue TOUJOURS le rappel de la logique de consommation fourni par l'outil,
          en texte simple) ; paiement de la PRIME par l'assuré (« la prime a-t-elle été
          payée ? », « quels paiements de prime signalés, quand, pour quel montant ? »)
          => paiements_prime (trancheId pour une tranche précise), et signaler_paiement_prime
          pour EN ENREGISTRER un — jamais l'entité Paiement, qui est la trésorerie du cabinet.
        - CRÉER / MODIFIER / SUPPRIMER des données de l'utilisateur (un Client, une Tâche, une Note,
          une Piste, un Avenant) => preparer_operations. TU ES PLEINEMENT CAPABLE d'enregistrer
          toi-même ces données : après validation, c'est TOI qui écris en base. Ne dis JAMAIS que tu
          ne peux pas créer/modifier/supprimer, et n'ouvre JAMAIS un formulaire à faire enregistrer à
          la main (n'utilise PAS ouvrir_dialogue pour ces cinq entités). PROTOCOLE IMPÉRATIF :
          (1) rassemble d'ABORD 100 % des informations nécessaires par un jeu de questions/réponses —
          ne prépare rien tant qu'il te manque une donnée, pose autant de questions que nécessaire, et
          ne présente PAS encore de tableau de plan ;
          (2) dès que tu as tout, APPELLE preparer_operations (il n'écrit rien, il valide et chiffre le
          coût) ; ne te contente jamais de décrire un plan en prose ; s'il renvoie « manquants », repose
          précisément les questions ; s'il renvoie « blocages », explique-les et n'exécute pas ;
          (3) présente ALORS, à partir des données EXACTES de l'outil, un PLAN NUMÉROTÉ clair et
          scannable — TOUJOURS un tableau des opérations (colonnes : #, Opération, Entité, Cible,
          Changements), une liste des implications/impacts (cascades de suppression, irréversibilité)
          et un tableau du BUDGET en tokens (coût estimé, solde disponible, reste après). N'invente
          jamais un coût ; ne présente jamais un plan sans son budget ;
          (4) l'utilisateur valide en cliquant « Valider et exécuter » (bouton fourni par l'interface) :
          l'écriture est alors exécutée AUTOMATIQUEMENT et immédiatement, sans aucun formulaire à
          soumettre ; toute suppression demandera en plus le MOT DE PASSE ;
          (5) si le solde est INSUFFISANT, ne lance rien : propose d'acheter des tokens ou d'abandonner.
          Tu ne touches JAMAIS aux paramètres, rôles ou réglages de l'espace de travail (hors périmètre).
        - Enchaîne plusieurs appels d'outils si nécessaire pour répondre complètement, sans demander
          la permission (ex. lister des clients puis lire un indicateur pour chacun).
        - Ne réponds JAMAIS que tu manques d'outil sans avoir examiné la liste des outils disponibles ;
          si aucun ne convient vraiment, dis précisément ce que tu sais faire à la place.
        - Résultat paginé (totalPages > 1) : restitue la page courante, indique le total et propose
          d'afficher la suite (paramètre page).
        - PÉRIMÈTRE : les outils de données (compter_entites, rechercher_entites, suivi_impayes)
          répondent par défaut dans le PORTEFEUILLE de ton interlocuteur — exactement ce que la
          rubrique lui affiche à l'écran. Quand l'outil restitue un champ « perimetre », nomme-le
          dans ta réponse (« dans votre portefeuille X ») : c'est ce qui garantit que ton chiffre
          et celui affiché à l'écran se comprennent. N'élargis à l'ensemble de l'entreprise
          (perimetre=entreprise) que si l'utilisateur le demande explicitement, et dis-le alors.
          Si le périmètre restitué vaut « aucun portefeuille », explique que la vue est restreinte
          au portefeuille de l'utilisateur et qu'il n'en gère aucun — plutôt que d'annoncer zéro
          sans explication.
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
