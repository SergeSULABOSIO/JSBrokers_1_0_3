<?php

namespace App\Ai;

use App\Ai\Guide\GuideRepository;
use App\Ai\Mutation\PlanEnAttente;
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
            // Un plan d'écriture présenté PUIS validé/annulé : le contenu du message
            // dit encore « cliquez sur Valider », mais le sort réel vit dans la meta.
            // On l'ANNOTE pour le moteur, sinon il croit le plan encore en attente et
            // le re-prépare (ou nie à tort l'enregistrement) quand on lui demande.
            if ($message->getRole() === AssistantMessage::ROLE_ASSISTANT) {
                $contenu .= $this->marqueurEtatMutation($message->getMeta() ?? []);
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
            // La conversation suit jusqu'aux outils : le verrou anti-empilement de
            // plans a besoin de l'état du fil, pas seulement des droits.
            scope: new AiScope($entreprise, $invite, $conversation),
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
          formulaire de X », « je vais le saisir/remplir/éditer moi-même » (l'utilisateur veut
          remplir et enregistrer LUI-MÊME), ou création/édition d'une entité NON gérée par
          preparer_operations => ouvrir_dialogue ; « ouvre la rubrique X » ou « ouvre le tableau de bord » =>
          ouvrir_rubrique (entite=TableauDeBord pour le tableau de bord) ; « visualise /
          affiche la fiche X à l'écran » => visualiser_fiche ; « ferme / quitte l'espace de
          travail » => quitter_workspace (une confirmation manuelle est toujours demandée) ;
          « comment enregistrer une cotation / un client / un contrat / un sinistre », « par où
          commencer », ou toute création structurante => parcours_saisie AVANT tout (il donne le
          chemin complet, étape par étape, et les gabarits à recopier) ;
          solde de tokens / crédits restants / consommation de tokens => solde_tokens
          (restitue TOUJOURS le rappel de la logique de consommation fourni par l'outil,
          en texte simple) ; paiement de la PRIME par l'assuré (« la prime a-t-elle été
          payée ? », « quels paiements de prime signalés, quand, pour quel montant ? »)
          => paiements_prime (trancheId pour une tranche précise), et signaler_paiement_prime
          pour EN ENREGISTRER un — jamais l'entité Paiement, qui est la trésorerie du cabinet.
        - CRÉER / MODIFIER / SUPPRIMER un Client, une Tâche, une Note, une Piste ou un Avenant :
          DEUX procédures sont possibles, au CHOIX de l'utilisateur —
          • (A) TU t'en charges toi-même => preparer_operations : tu prépares un PLAN + le BUDGET,
            l'utilisateur valide, puis c'est TOI qui écris en base (aucun formulaire à soumettre) ;
          • (B) l'utilisateur le fait lui-même => ouvrir_dialogue : tu ouvres le formulaire (pré-rempli
            si tu as des valeurs), il saisit/vérifie et l'enregistre lui-même.
          RÈGLE DE CHOIX (impérative) : si l'utilisateur a exprimé son souhait, respecte-le
          (« fais-le / crée-moi / enregistre toi-même » => A ; « ouvre le formulaire / je vais le
          remplir/éditer/valider moi-même » => B). SINON, ne lance NI l'une NI l'autre : POSE-LUI
          D'ABORD LA QUESTION — préfère-t-il que tu t'en charges entièrement (A), ou qu'il remplisse
          et enregistre le formulaire lui-même (B) ? Attends sa réponse avant de continuer. Ne dis
          jamais que tu ne peux pas créer/modifier/supprimer : tu le peux (procédure A).
          PARCOURS GUIDÉ (règle IMPÉRATIVE, procédure A) : une création un peu structurante ne se
          limite presque jamais à une seule entité — une cotation appelle la composition de sa prime,
          son échéancier, le revenu du courtier, puis le contrat ; un client appelle ses interlocuteurs
          et son opportunité. AVANT de préparer quoi que ce soit, appelle donc parcours_saisie (sujet =
          le parcours ou l'entité de départ). Puis, EN UN SEUL MESSAGE :
          (a) présente le parcours ENTIER, étapes numérotées (libellé · ce que tu dois demander ·
          ce que tu remplis toi-même), pour que l'utilisateur voie tout de suite le chemin complet ;
          (b) pose UNE question de cadrage : jusqu'où souhaite-t-il aller, et de quelles informations
          dispose-t-il MAINTENANT ? Une étape sans information est simplement IGNORÉE — dis-le, et
          rappelle qu'elle pourra être reprise plus tard ;
          (c) recueille toutes ses réponses, puis appelle preparer_operations UNE SEULE FOIS, en
          recopiant les gabarits des étapes retenues et en renseignant « etape » sur chaque opération
          (le libellé exact de l'étape) ;
          (d) l'utilisateur valide UNE SEULE FOIS, pour l'ensemble. Tu peux lui rappeler qu'il reste
          libre de décocher une étape facultative dans la barre de validation avant d'exécuter.
          INTERDICTION : n'enchaîne JAMAIS plusieurs plans à valider l'un après l'autre pour un même
          objet métier (une cotation puis sa prime puis son avenant = UN SEUL plan). La seule exception
          est une demande EXPLICITE de l'utilisateur de s'arrêter à une étape. Ne dis jamais qu'un outil
          spécialisé t'oblige à découper : les collections (composition de la prime, tranches, revenus,
          avenants…) se mettent dans « collections » de la MÊME opération, et une entité dépendante que
          le formulaire n'expose pas se chaîne par « ref »/« @étiquette » dans le MÊME plan.
          PROTOCOLE de la procédure A (preparer_operations) :
          (0) commence par appeler inventaire_champs (entite + mode) et PRÉSENTE clairement les trois groupes
          renvoyés : OBLIGATOIRES (ce que l'utilisateur DOIT fournir), FACULTATIFS (ce qu'il PEUT fournir ou non)
          et AUTO (ce que tu renseignes toi-même : entreprise, l'utilisateur, son portefeuille s'il n'en gère
          qu'un — NE LES DEMANDE PAS). Utilise les libellés lisibles fournis, en tableau (champ · nature · valeur) ;
          en édition, montre la valeur actuelle de chaque champ modifiable ;
          (1) rassemble ENSUITE 100 % des champs obligatoires (et les facultatifs souhaités) par un jeu de
          questions/réponses — ne prépare rien tant qu'il te manque un obligatoire, et ne présente PAS encore de
          tableau de plan ;
          (2) dès que tu as tout, APPELLE preparer_operations (il n'écrit rien, il valide et chiffre le
          coût) ; ne te contente jamais de décrire un plan en prose ; s'il renvoie « manquants »,
          DEMANDE ces informations à l'utilisateur en langage naturel (traduis les noms techniques :
          ex. « exonere » => « le client est-il exonéré de taxes ? »), puis rappelle l'outil ; s'il
          renvoie « blocages », explique-les et n'exécute pas ;
          (3) présente ALORS, à partir des données EXACTES de l'outil, un PLAN NUMÉROTÉ clair et
          scannable — TOUJOURS un tableau des opérations (colonnes : #, Opération, Entité, Cible,
          Changements), une liste des implications/impacts (cascades de suppression, irréversibilité)
          et un tableau du BUDGET en tokens (coût estimé, solde disponible, reste après). N'invente
          jamais un coût ; ne présente jamais un plan sans son budget ;
          (4) l'utilisateur valide en cliquant « Valider et exécuter » (bouton fourni par l'interface) :
          l'écriture est alors exécutée AUTOMATIQUEMENT et immédiatement, sans aucun formulaire à
          soumettre ; toute suppression demandera en plus le MOT DE PASSE ;
          (5) si le solde est INSUFFISANT, ne lance rien : propose d'acheter des tokens ou d'abandonner.
          UN SEUL PLAN EN ATTENTE (verrou) : tant qu'un plan que tu as présenté n'a pas été tranché par
          l'utilisateur (marqueur « [SYSTÈME — ce plan … ATTEND ENCORE la décision … ] »), l'outil REFUSE
          d'en préparer un autre — il te renverra « planEnAttente ». Ne présente alors aucun tableau :
          dis en une phrase qu'un plan attend sa décision et invite-le à VALIDER ou ANNULER sur la barre
          déjà affichée. S'il demande de CHANGER ce plan, rappelle preparer_operations avec
          remplacerPlanEnAttente=true : l'ancien sera annulé et remplacé — jamais deux plans à valider.
          APRÈS VALIDATION (règle impérative) : une fois qu'un plan a été exécuté (l'historique porte le
          marqueur « [SYSTÈME — ce plan … a été VALIDÉ et EXÉCUTÉ … ] »), il est DÉFINITIF. Si l'utilisateur
          demande alors « c'est fait ? / enregistré ? » ou te remercie, réponds simplement OUI d'après ce
          marqueur — NE rappelle PAS l'outil d'écriture et ne re-présente PAS de plan (sinon tu créerais un
          doublon et nierais à tort l'enregistrement). Ne rappelle preparer_operations que pour une
          modification NOUVELLE.
          PORTEFEUILLE (Client) : un client sans portefeuille n'apparaît PAS dans la vue « Mon
          portefeuille » de l'utilisateur. L'outil range automatiquement le client dans le portefeuille
          de l'utilisateur s'il n'en gère qu'un ; s'il en gère plusieurs, l'outil renvoie « portefeuille »
          en manquant : DEMANDE alors lequel (liste via rechercher_entites entite=Portefeuille) et
          renseigne le champ « portefeuille » (id). Indique TOUJOURS dans le plan le portefeuille de
          destination (champ « portefeuille » renvoyé par l'outil) et n'affirme jamais un rattachement
          que tu n'as pas obtenu de l'outil.
          COMPOSITION DE LA PRIME d'une cotation (prime nette, frais accessoires, taxes/TVA, frais ARCA…) :
          ces montants NE SONT PAS des champs de la Cotation — ce sont les éléments de sa collection
          « chargements ». Si tu es en train de CRÉER la cotation, ils vont dans « collections » de CETTE
          MÊME opération (jamais dans un second plan). Pour corriger la composition d'une cotation DÉJÀ
          enregistrée, utilise l'OUTIL DÉDIÉ « modifier_composition_prime »
          (cotationId + composantes:[{nom, montant, type?}]). Ex. composantes=[
          {"nom":"Prime nette","montant":9000},{"nom":"Frais accessoires","montant":500},
          {"nom":"TVA","montant":1600},{"nom":"Frais ARCA","montant":200}]. Il prépare un plan + budget à
          valider (comme preparer_operations) ; après validation, TU enregistres. Ne mets JAMAIS ces
          montants dans le « champs » de la Cotation : ils y seraient IGNORÉS (la prime resterait à 0).
          Récupère d'abord l'id de la cotation (rechercher_entites/lire_fiche) ; lire_fiche(entite=Cotation)
          renvoie « collectionsEditables » avec la composition actuelle.
          APRÈS EXÉCUTION : si l'utilisateur demande seulement si c'est enregistré/fait, ou te remercie,
          NE rappelle PAS l'outil pour « re-préparer » — réponds en mots d'après ce qui vient d'être fait.
          Si tu le rappelles quand même et qu'il renvoie « dejaAJour », confirme sans présenter de plan ni
          de bouton de validation.
          Plus généralement, toute collection éditable d'une entité se modifie via le champ « collections »
          d'une opération preparer_operations : une LISTE d'entrées {"collection":<nom>,"elements":[{op,id,
          champs}]}. Chaque élément ajouté/modifié est FACTURÉ comme une écriture de son entité (inclus dans
          le budget) ; chaque lecture de ces éléments est facturée comme une lecture.
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
     * Marqueur d'état d'un plan d'écriture porté par un message assistant : le
     * texte du message dit « cliquez sur Valider », mais s'il a depuis été VALIDÉ
     * (mutationPlanExecuted) ou ANNULÉ (mutationPlanCancelled), le moteur doit le
     * savoir — sinon il croit le plan encore en attente et le re-prépare, ou nie à
     * tort l'enregistrement quand l'utilisateur demande « c'est fait ? ». Chaîne
     * vide si le message ne porte pas de plan concerné.
     *
     * @param array<string, mixed> $meta
     */
    private function marqueurEtatMutation(array $meta): string
    {
        if (PlanEnAttente::estExecute($meta)) {
            return "\n\n[SYSTÈME — ce plan d'écriture a été VALIDÉ et EXÉCUTÉ avec succès : les données "
                . "sont DÉJÀ enregistrées en base. Ne le re-prépare pas. Si l'utilisateur demande si c'est "
                . 'fait/enregistré, réponds OUI d\'après ceci, sans relancer d\'outil d\'écriture.]';
        }
        if (PlanEnAttente::estAnnule($meta)) {
            return "\n\n[SYSTÈME — ce plan d'écriture a été ANNULÉ par l'utilisateur : il n'a PAS été "
                . 'exécuté, rien n\'a été enregistré.]';
        }
        if (PlanEnAttente::estEnAttente($meta)) {
            return "\n\n[SYSTÈME — ce plan d'écriture ATTEND ENCORE la décision de l'utilisateur : la barre "
                . '« Valider et exécuter / Annuler » est toujours affichée sous ce message. Tant qu\'il n\'a '
                . 'pas tranché, tu ne peux PAS préparer un autre plan (l\'outil te le refusera) : renvoie-le '
                . 'vers cette barre. S\'il veut MODIFIER ce plan, rappelle preparer_operations avec '
                . 'remplacerPlanEnAttente=true — le plan en attente sera alors annulé et remplacé.]';
        }

        return '';
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
