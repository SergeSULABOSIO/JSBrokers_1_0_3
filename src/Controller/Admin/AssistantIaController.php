<?php

namespace App\Controller\Admin;

use App\Ai\AiContextBuilder;
use App\Ai\AiEngineFailure;
use App\Ai\Boussole\PlanDuJourService;
use App\Ai\AiReply;
use App\Ai\Engine\AiEngineInterface;
use App\Ai\Export\ImageJointeValidator;
use App\Ai\Export\MessageDestinataires;
use App\Ai\Export\MessageExporter;
use App\Ai\Export\MessageMailNotifier;
use App\Ai\Fichier\FichierAttachePolicy;
use App\Ai\Fichier\FichierTexteExtracteur;
use App\Ai\Programme\ProgrammeEnCours;
use App\Ai\Programme\ProgrammeRunner;
use App\Ai\Programme\ProgrammeVerificateur;
use App\Ai\Programme\RapportProgramme;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Tool\EntiteLibelle;
use App\Ai\Tool\PrefillWhitelist;
use Psr\Log\LoggerInterface;
use App\Entity\AssistantConversation;
use App\Entity\AssistantConversationContexte;
use App\Entity\AssistantConversationFichier;
use App\Entity\AssistantMessage;
use App\Entity\AssistantParametres;
use App\Entity\AssistantProgramme;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Repository\AssistantConversationRepository;
use App\Repository\AssistantMessageRepository;
use App\Repository\AssistantParametresRepository;
use App\Repository\AssistantProgrammeRepository;
use App\Repository\EntrepriseRepository;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Mutation\MutationReferences;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\MutationException;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\Canvas\CanvasRelationHydrator;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use App\Token\InsufficientTokensException;
use App\Token\TokenAccountService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Vich\UploaderBundle\Handler\DownloadHandler;

/**
 * @file Assistant IA de l'espace de travail du courtier.
 * @description Rubrique « Assistant » (col-3 : liste des conversations de
 * l'invité) + partial de chat (ouvert en col-4, la colonne de visualisation) +
 * API conversations/messages, et rubrique « Paramètres IA » (nom du personnage,
 * réservée au propriétaire/gestionnaire d'invités).
 *
 * Sécurité (fail-closed, patron DocumentComptableWorkspaceController) :
 *  - resolveWorkspace() refuse tout invité d'une AUTRE entreprise ;
 *  - chaque conversation n'est servie qu'à SON invité (findOneDeLInvite → 404) ;
 *  - le périmètre des DONNÉES est garanti par les outils IA eux-mêmes
 *    (AiToolInterface::execute vérifie canRead), pas par ce contrôleur : la
 *    rubrique est donc visible de tous les invités de l'entreprise, et un
 *    invité sans rôle obtient un assistant qui explique poliment ses limites.
 *
 * Tokens : chaque message envoyé à l'assistant est métré en écriture AVANT tout
 * traitement (AssistantMessage, poids paramétrable) → 402 JSON si solde épuisé,
 * sans rien persister.
 */
#[Route('/admin/assistant-ia', name: 'admin.assistantia.')]
#[IsGranted('ROLE_USER')]
class AssistantIaController extends AbstractController
{
    private const MAX_MESSAGE_LENGTH = 4000;

    /** Nombre maximal d'objets attachés au contexte d'une même conversation. */
    private const MAX_CONTEXTES = 20;

    /**
     * Plafond d'envois par e-mail d'un MÊME message. Un e-mail sortant porte la
     * marque JS Brokers vers un tiers, et l'adresse peut être saisie librement :
     * ce plafond borne l'abus d'un message donné. Il se lit en O(1) dans
     * meta['envois'], sans table ni requête supplémentaire. (Un quota GLOBAL par
     * entreprise demanderait symfony/rate-limiter, absent du projet.)
     */
    private const MAX_ENVOIS_PAR_MESSAGE = 10;

    public function __construct(
        private EntrepriseRepository $entrepriseRepository,
        private AssistantConversationRepository $conversationRepository,
        private AssistantMessageRepository $messageRepository,
        private AssistantParametresRepository $parametresRepository,
        private WorkspaceAccessResolver $accessResolver,
        private TokenAccountService $tokenAccountService,
        private AiContextBuilder $contextBuilder,
        private AiEngineInterface $aiEngine,
        private JournalTokens $journalTokens,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private CanvasBuilder $canvasBuilder,
        private JSBDynamicSearchService $searchService,
        private NormalizerInterface $normalizer,
        private PrefillWhitelist $prefillWhitelist,
        private EntiteLibelle $libelleur,
        private WorkspaceMutationService $mutationService,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator,
        private FichierTexteExtracteur $fichierExtracteur,
        private PlanDuJourService $planDuJour,
        private AssistantProgrammeRepository $programmeRepository,
        private ProgrammeEnCours $programmeEnCours,
        private ProgrammeRunner $programmeRunner,
        private ProgrammeVerificateur $programmeVerificateur,
        private CanvasRelationHydrator $relationHydrator,
    ) {
    }

    /**
     * Composant col-3 de la rubrique « Assistant » : entête personnage + liste
     * des conversations de l'invité + bouton nouvelle conversation. Atteint par
     * le « Cerveau » via forwardToComponent, rechargé en AJAX par le contrôleur
     * Stimulus `assistant-ia`.
     */
    #[Route('/workspace/{idEntreprise}', name: 'workspace', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function loadWorkspaceComponent(int $idEntreprise): Response
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);

        // FAIL-CLOSED : accès au MODULE (pseudo-entité AssistantIa — rôle
        // Administration). Le menu n'est que cosmétique, on re-vérifie ici.
        if (!$this->moduleAutorise($invite)) {
            return $this->render('components/_access_denied.html.twig', ['entiteNom' => 'Assistant IA']);
        }

        // PREMIUM : l'assistant est réservé aux comptes disposant d'un solde
        // de tokens payant (l'allocation gratuite ne suffit pas).
        if (!$this->tokenAccountService->estComptePayant($entreprise)) {
            return $this->renderPremium($entreprise, $invite);
        }

        return $this->render('components/_assistant_ia_component.html.twig', [
            'assistantNom'  => $this->parametresRepository->nomPour($entreprise),
            'conversations' => $this->conversationRepository->findPourInvite($invite, $entreprise),
            'idEntreprise'  => $idEntreprise,
            'peutParametrer' => $this->accessResolver->canManageInvites($invite),
        ]);
    }

    /**
     * Composant « Paramètres IA » : nommage du personnage de l'entreprise.
     * Réservé au propriétaire / gestionnaire d'invités (le menu n'est que
     * cosmétique, on re-vérifie ici).
     */
    #[Route('/workspace-parametres/{idEntreprise}', name: 'workspace_parametres', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function loadParametresComponent(int $idEntreprise, Request $request): Response
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if ($invite === null || !$this->accessResolver->canManageInvites($invite)) {
            return $this->render('components/_access_denied.html.twig', ['entiteNom' => 'Paramètres IA']);
        }

        $parametres = $this->parametresRepository->findOneByEntreprise($entreprise);
        $erreur = null;
        $enregistre = false;

        if ($request->isMethod('POST')) {
            $nom = trim((string) $request->request->get('nom', ''));
            if (mb_strlen($nom) < 2 || mb_strlen($nom) > 60) {
                $erreur = 'Le nom de l\'assistant doit contenir entre 2 et 60 caractères.';
            } else {
                $parametres ??= (new AssistantParametres())->setEntreprise($entreprise);
                $parametres->setNom($nom);

                try {
                    $this->tokenAccountService->meterWrite($parametres, $entreprise, $this->currentUser());
                } catch (InsufficientTokensException $e) {
                    return $this->render('components/_tokens_blocked.html.twig', [
                        'nextRenewalAt' => $e->nextRenewalAt,
                        'required'      => $e->required,
                        'available'     => $e->available,
                    ]);
                }

                $this->em->persist($parametres);
                $this->em->flush();
                $enregistre = true;
            }
        }

        return $this->render('components/_assistant_ia_parametres_component.html.twig', [
            'assistantNom'   => $parametres?->getNom() ?? AssistantParametres::NOM_PAR_DEFAUT,
            'nomParDefaut'   => AssistantParametres::NOM_PAR_DEFAUT,
            'idEntreprise'   => $idEntreprise,
            'enregistre'     => $enregistre,
            'erreur'         => $erreur,
            'utilisateurNom' => $this->currentUser()->getNom(),
            'entrepriseNom'  => $entreprise->getNom(),
        ]);
    }

    /**
     * Partial du chat d'une conversation — injecté par le front dans la
     * colonne n°4 (visualisation) via l'événement
     * `app:workspace.open-html-in-visualization`.
     */
    #[Route('/chat/{idEntreprise}/{idConversation}', name: 'chat', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS], methods: ['GET'])]
    public function chat(int $idEntreprise, int $idConversation): Response
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->render('components/_access_denied.html.twig', ['entiteNom' => 'Assistant IA']);
        }
        if (!$this->tokenAccountService->estComptePayant($entreprise)) {
            return $this->renderPremium($entreprise, $invite);
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        return $this->render('components/_assistant_ia_chat.html.twig', [
            'conversation'    => $conversation,
            'assistantNom'    => $this->parametresRepository->nomPour($entreprise),
            // PROGRAMME DU JOUR : calculé uniquement pour une conversation VIDE, où il
            // remplace la bulle d'accueil. Rendu serveur = aucun token consommé et
            // aucun risque d'invention : ce sont les chiffres des rubriques, tels quels.
            // Le service est fail-safe, mais l'ouverture du chat ne doit dépendre de
            // rien : un pépin ici retombe simplement sur l'accueil ordinaire.
            'planDuJour'      => $conversation->getMessages()->isEmpty()
                ? $this->planDuJourOuNull($entreprise, $invite)
                : null,
            'entreprise'      => $entreprise,
            'idEntreprise'    => $idEntreprise,
            'idInvite'        => $invite->getId(),
            'fichesContextes' => $this->fichesContextes($conversation),
            // Thème du chat : 'light' / 'dark' si l'utilisateur a tranché,
            // 'auto' sinon → le contrôleur Stimulus résout via
            // `prefers-color-scheme` avant le premier rendu visuel.
            'themeAssistant'  => $this->currentUser()->getThemeAssistant() ?? 'auto',
        ]);
    }

    /**
     * Programme du jour de l'invité, ou `null` si le calcul échoue ou n'a rien à
     * dire. Le template retombe alors sur le message d'accueil ordinaire — mieux
     * vaut un accueil neutre qu'un chat qui ne s'ouvre pas.
     */
    private function planDuJourOuNull(Entreprise $entreprise, Invite $invite): ?array
    {
        try {
            $plan = $this->planDuJour->plan($entreprise, $invite);
        } catch (\Throwable $e) {
            $this->logger->warning('[AssistantIa] Programme du jour indisponible.', [
                'entreprise' => $entreprise->getId(),
                'invite' => $invite->getId(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return $plan['toutAuVert'] ? null : $plan;
    }

    /**
     * Bascule du thème du chat (confort visuel). Préférence d'AFFICHAGE portée
     * par l'utilisateur — donc valable sur tous ses appareils, comme `locale`,
     * et sans dépendance à une entreprise ou à une conversation.
     *
     * AUCUN métrage de tokens : ce n'est pas une écriture métier, pas plus que
     * le choix de la langue. La bascule doit rester gratuite et instantanée.
     */
    #[Route('/api/theme', name: 'api.theme', methods: ['POST'])]
    public function setTheme(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?: [];
        $theme = $payload['theme'] ?? null;

        if (!in_array($theme, Utilisateur::THEMES, true)) {
            return $this->json([
                'message' => 'Thème inconnu : attendu « light » ou « dark ».',
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->currentUser()->setThemeAssistant($theme);
        $this->em->flush();

        return $this->json(['success' => true, 'theme' => $theme]);
    }

    /** Crée une conversation vide pour l'invité courant. */
    #[Route('/api/conversations/{idEntreprise}', name: 'api.conversation.create', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['POST'])]
    public function createConversation(int $idEntreprise): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }
        if ($blocage = $this->blocagePremium($entreprise)) {
            return $blocage;
        }

        $conversation = (new AssistantConversation())
            ->setEntreprise($entreprise)
            ->setInvite($invite);
        $this->em->persist($conversation);
        $this->em->flush();

        return $this->json([
            'id'      => $conversation->getId(),
            'titre'   => $conversation->getTitre(),
            'chatUrl' => $this->generateUrl('admin.assistantia.chat', [
                'idEntreprise'   => $idEntreprise,
                'idConversation' => $conversation->getId(),
            ]),
        ]);
    }

    /** Renomme une conversation de l'invité. */
    #[Route('/api/conversations/{idEntreprise}/{idConversation}', name: 'api.conversation.rename', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS], methods: ['PATCH'])]
    public function renameConversation(int $idEntreprise, int $idConversation, Request $request): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        $payload = json_decode($request->getContent(), true) ?: [];
        $titre = trim((string) ($payload['titre'] ?? ''));
        if ($titre === '' || mb_strlen($titre) > 120) {
            return $this->json([
                'message' => 'Le titre doit contenir entre 1 et 120 caractères.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $conversation->setTitre($titre);
        $this->em->flush();

        return $this->json(['success' => true, 'titre' => $conversation->getTitre()]);
    }

    /** Supprime une conversation de l'invité (messages en cascade). */
    #[Route('/api/conversations/{idEntreprise}/{idConversation}', name: 'api.conversation.delete', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS], methods: ['DELETE'])]
    public function deleteConversation(int $idEntreprise, int $idConversation): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        // Suppression en UNE requête SQL : la FK ON DELETE CASCADE de la base
        // emporte les messages. $em->remove() chargerait toute la collection
        // puis émettrait un DELETE par message (orphanRemoval) — inutilement
        // lent sur une longue conversation.
        $this->em->createQuery('DELETE FROM App\Entity\AssistantConversation c WHERE c.id = :id')
            ->setParameter('id', $conversation->getId())
            ->execute();

        return $this->json(['success' => true]);
    }

    /**
     * Envoi d'un message à l'assistant : métrage AVANT tout traitement, puis
     * moteur IA, puis persistance des deux messages (question + réponse).
     */
    #[Route('/api/messages/{idEntreprise}/{idConversation}', name: 'api.message.send', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS], methods: ['POST'])]
    public function sendMessage(int $idEntreprise, int $idConversation, Request $request): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }
        if ($blocage = $this->blocagePremium($entreprise)) {
            return $blocage;
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        $payload = json_decode($request->getContent(), true) ?: [];
        $contenu = trim((string) ($payload['contenu'] ?? ''));
        if ($contenu === '') {
            return $this->json(['message' => 'Le message est vide.'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($contenu) > self::MAX_MESSAGE_LENGTH) {
            return $this->json([
                'message' => sprintf('Le message dépasse la taille maximale (%d caractères).', self::MAX_MESSAGE_LENGTH),
            ], Response::HTTP_BAD_REQUEST);
        }

        // Citation (« Répondre » du menu de bulle) : le message cité doit
        // appartenir à CETTE conversation, elle-même déjà restreinte à cet invité
        // et à cette entreprise. Vérifié AVANT le métrage : une requête invalide
        // ne doit rien consommer.
        //
        // 400 — et non 404 — dans tous les cas (id inexistant, autre conversation,
        // autre invité) : réponses indiscernables, donc aucun oracle permettant
        // d'énumérer les ids de messages. Le 404 reste réservé à « conversation
        // introuvable » (requireConversation).
        $repondA = null;
        $replyToId = $payload['replyToId'] ?? null;
        if ($replyToId !== null) {
            $repondA = $this->messageRepository->findDansConversation((int) $replyToId, $conversation);
            if ($repondA === null) {
                return $this->json(
                    ['message' => 'Le message cité est introuvable dans cette conversation.'],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        $messageUser = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_USER)
            ->setContenu($contenu)
            ->setRepondA($repondA)
            // Instantané IMMUABLE : le message « transporte » les objets du contexte
            // tels qu'ils étaient à l'envoi (agrafe sur la bulle + annotation de
            // l'historique moteur) — la liste courante de la conversation, elle,
            // continuera d'évoluer sans réécrire ce cliché.
            ->setContexteObjets($this->instantaneContexte($conversation))
            // Même logique pour les pièces jointes attachées à l'envoi.
            ->setFichiersJoints($this->instantaneFichiers($conversation));

        // MÉTRAGE (écriture) avant moteur et persistance : si le solde est
        // épuisé, rien n'est traité ni enregistré.
        try {
            $this->tokenAccountService->meterWrite($messageUser, $entreprise, $this->currentUser());
        } catch (InsufficientTokensException $e) {
            return $this->json([
                'message'       => 'Quota de tokens épuisé. Rechargez votre solde ou attendez le renouvellement de votre allocation gratuite.',
                'blocked'       => true,
                'required'      => $e->required,
                'available'     => $e->available,
                'nextRenewalAt' => $e->nextRenewalAt?->format(\DateTimeImmutable::ATOM),
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $conversation->addMessage($messageUser);

        // Le moteur réel (API Claude/Gemini) peut échouer (réseau, quota, clé) :
        // la conversation reste utilisable — réponse d'excuse persistée (honnête
        // sur la cause quand elle est identifiable, cf. AiEngineFailure), pas de 500.
        $erreurMoteur = false;
        // Construite DANS le try (une construction qui échoue doit produire
        // l'excuse, pas un 500) mais déclarée avant, pour rester disponible au
        // moment de journaliser l'échec.
        $aiRequest = null;
        try {
            $aiRequest = $this->contextBuilder->build($entreprise, $invite, $conversation);
            $reply = $this->aiEngine->reply($aiRequest);
        } catch (\Throwable $e) {
            $quotaEpuise = AiEngineFailure::estLimiteDeDebit($e);
            // Sur un 429, le corps de la réponse nomme le quota violé et le délai
            // d'attente : sans ces champs le journal ne dit que « HTTP 429 » et la
            // saturation reste indiagnosticable.
            $detailsQuota = $quotaEpuise ? AiEngineFailure::detailsPourJournal($e) : [];
            $this->logger->error('Assistant IA : le moteur a échoué.', [
                'exception' => $e,
                'engine'    => $this->aiEngine->name(),
            ] + ($quotaEpuise ? ['quota' => $detailsQuota] : []));

            // Les tours déjà effectués ont été payés : ils doivent figurer dans la
            // campagne de mesure, sans quoi les messages les plus coûteux — ceux
            // qui butent sur le quota — seraient précisément ceux qui manquent.
            if ($aiRequest !== null) {
                $this->journalTokens->messageInterrompu(
                    $aiRequest,
                    $this->aiEngine->name(),
                    $this->aiEngine->modelName(),
                    $quotaEpuise ? JournalTokens::ISSUE_QUOTA_FOURNISSEUR : JournalTokens::ISSUE_ECHEC_TECHNIQUE,
                    $detailsQuota,
                );
            }

            $erreurMoteur = true;
            $reply = new AiReply(AiEngineFailure::messagePour($e));
        }

        // Un plan de mutation préparé par Ket (uiAction ket-mutation.review) est
        // STOCKÉ côté serveur (meta du message) : l'endpoint d'exécution le
        // rechargera et le re-validera intégralement — jamais de confiance au client.
        // Un seul plan par réponse : si le moteur a appelé deux fois l'outil de
        // préparation dans le MÊME tour (le verrou de conversation ne voit que les
        // tours précédents), seule la première barre de décision est conservée —
        // la seconde serait orpheline (aucun plan stocké derrière elle).
        $actions = PlanEnAttente::limiterAUnSeulPlan($reply->actions ?? []);
        $mutationPlan = $this->extraireMutationPlan($actions);

        // GARDE-FOU anti-plan FANTÔME. Le modèle peut décrire un plan, un budget,
        // voire affirmer qu'un « bouton de validation » est actif — le tout dans sa
        // seule PROSE, sans avoir appelé preparer_operations. Aucune action n'est
        // alors émise : aucun bouton ne s'affiche, et l'utilisateur attend une
        // décision qui ne viendra jamais (au pire le modèle invente ensuite un « bug
        // d'interface »). Quand la prose simule une décision alors qu'AUCUN plan n'a
        // été préparé ce tour-ci NI n'est en attente dans le fil, on émet un signal
        // AUTORITAIRE (serveur) qui dit la vérité — indépendamment de la prose.
        $planFantome = $mutationPlan === null
            && !$reply->refused
            && !PlanEnAttente::aUnPlanEnAttente($conversation)
            && PlanEnAttente::proseSimuleUneDecision((string) $reply->content);
        if ($planFantome) {
            $actions[] = ['type' => PlanEnAttente::ACTION_ABSENT];
            $this->logger->warning('Assistant IA : plan fantôme détecté (prose sans action de mutation).', [
                'conversation' => $conversation->getId(),
                'engine'       => $this->aiEngine->name(),
            ]);
        }

        $messageAssistant = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu($reply->content)
            ->setMeta(array_filter([
                'engine'       => $this->aiEngine->name(),
                'tool'         => $reply->toolUsed,
                'refus'        => $reply->refused ?: null,
                'erreur'       => $erreurMoteur ?: null,
                'actions'      => $actions ?: null,
                'mutationPlan' => $mutationPlan,
                // Trace du garde-fou : réaffiche l'avertissement autoritaire après
                // un rechargement de page (F5), comme les statuts de plan.
                'mutationAbsent' => $planFantome ?: null,
                // Bandeau d'avancement quand ce plan est l'étape d'un PROGRAMME
                // (« étape 1 sur 3 ») : persisté pour survivre au rechargement,
                // au même titre que le plan lui-même.
                'programme'      => $this->extraireBandeauProgramme($actions),
            ]));
        $conversation->addMessage($messageAssistant);

        if ($conversation->getTitre() === null) {
            $conversation->setTitre(mb_substr($contenu, 0, 80));
        }

        $this->em->flush();

        // La PREMIÈRE étape d'un programme voyage sur ce message-ci (l'outil ne
        // fabrique pas de bulle supplémentaire pour elle) : on rattache l'étape à
        // son message maintenant qu'il a une identité. C'est ce lien, et lui seul,
        // qui permettra à l'exécution de savoir qu'elle vient de trancher une
        // étape — et donc d'enchaîner sur la suivante.
        $programmeCourant = $this->programmeEnCours->courant($conversation);
        if ($programmeCourant !== null) {
            $this->programmeRunner->attacherMessage($programmeCourant, $messageAssistant);
        }

        return $this->json([
            'user' => [
                'id'             => $messageUser->getId(),
                'contenu'        => $messageUser->getContenu(),
                'contexteObjets' => $messageUser->getContexteObjets(),
                'fichiersJoints' => $messageUser->getFichiersJoints(),
                // Contrat testable de la persistance de la citation (le front
                // affiche déjà sa bulle optimiste sans attendre cette valeur).
                'citation'       => $repondA === null ? null : [
                    'id'      => $repondA->getId(),
                    'role'    => $repondA->getRole(),
                    'extrait' => $repondA->extraitCitation(),
                ],
                'createdAt'      => $messageUser->getCreatedAt()?->format(\DateTimeImmutable::ATOM),
            ],
            'assistant' => [
                'id'        => $messageAssistant->getId(),
                'contenu'   => $messageAssistant->getContenu(),
                'refus'     => $reply->refused,
                // Les actions renvoyées portent l'id du message : une action
                // ket-mutation.review sait ainsi vers quel endpoint d'exécution pointer.
                'actions'   => $this->actionsAvecMessage($actions, (int) $messageAssistant->getId()),
                'createdAt' => $messageAssistant->getCreatedAt()?->format(\DateTimeImmutable::ATOM),
            ],
            'conversationTitre' => $conversation->getTitre(),
        ]);
    }

    /**
     * Extrait le plan de mutation (plan + budget + exige-mdp) d'une éventuelle
     * action ket-mutation.review, pour stockage serveur. null si absent.
     *
     * Délègue à PlanEnAttente : la structure stockée est partagée avec la
     * préparation déterministe des étapes de programme (ProgrammeRunner), et les
     * deux chemins doivent écrire exactement la même chose.
     *
     * @param array<int, array> $actions
     */
    private function extraireMutationPlan(array $actions): ?array
    {
        return PlanEnAttente::planStockable($actions);
    }

    /**
     * Bandeau d'avancement du programme porté par une action de revue, ou null
     * quand le plan est isolé. Stocké à part du plan : le plan est ce qui sera
     * ÉCRIT, le bandeau est ce qui situe l'étape dans la série — deux choses
     * indépendantes, l'une n'ayant pas à polluer l'autre.
     *
     * @param array<int, array> $actions
     */
    private function extraireBandeauProgramme(array $actions): ?array
    {
        foreach ($actions as $action) {
            if (($action['type'] ?? null) === PlanEnAttente::ACTION_REVUE && is_array($action['programme'] ?? null)) {
                return $action['programme'];
            }
        }

        return null;
    }

    /**
     * Recopie les actions en injectant l'id du message assistant dans l'action
     * ket-mutation.review (le front en dérive l'URL d'exécution).
     *
     * @param array<int, array> $actions
     */
    private function actionsAvecMessage(array $actions, int $idMessage): array
    {
        return array_map(static function (array $action) use ($idMessage) {
            if (($action['type'] ?? null) === PlanEnAttente::ACTION_REVUE) {
                $action['idMessage'] = $idMessage;
            }

            return $action;
        }, $actions);
    }

    /**
     * Exécute un plan de mutation préparé par Ket (create/edit/delete), stocké
     * dans la meta du message assistant qui l'a présenté. HORS-LLM, déterministe :
     *  1) recharge et RE-VALIDE le plan intégralement (droits, scope, cibles) ;
     *  2) contrôle de SOLVABILITÉ (coût estimé ≤ solde), sinon 402 + CTA d'achat ;
     *  3) si une suppression est présente, exige le MOT DE PASSE (403 sinon) ;
     *  4) exécute en UNE transaction (rollback global au moindre échec) ;
     *  5) renvoie le JOURNAL d'étapes (rejoué séquentiellement côté chat).
     */
    #[Route('/api/mutation/{idEntreprise}/{idConversation}/{idMessage}/execute', name: 'api.mutation.execute', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS, 'idMessage' => Requirement::DIGITS], methods: ['POST'])]
    public function executeMutation(int $idEntreprise, int $idConversation, int $idMessage, Request $request): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }
        if ($blocage = $this->blocagePremium($entreprise)) {
            return $blocage;
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        // Le plan est relu depuis la meta du message (jamais depuis le client).
        $message = $this->trouverMessage($conversation, $idMessage);
        $meta = $message?->getMeta() ?? [];
        $stored = $meta['mutationPlan'] ?? null;
        if ($message === null || !is_array($stored) || !isset($stored['plan'])) {
            return $this->json(['message' => 'Plan introuvable ou expiré.'], Response::HTTP_NOT_FOUND);
        }
        if (PlanEnAttente::estExecute($meta)) {
            return $this->json(['message' => 'Ce plan a déjà été exécuté.'], Response::HTTP_CONFLICT);
        }
        if (PlanEnAttente::estAnnule($meta)) {
            return $this->json(['message' => 'Ce plan a été annulé.'], Response::HTTP_CONFLICT);
        }

        $plan = MutationPlan::fromArray((array) $stored['plan']);
        if ($plan->estVide()) {
            return $this->json(['message' => 'Plan vide.'], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($request->getContent(), true) ?: [];

        // 1 bis) ÉTENDUE choisie par l'utilisateur : il a pu décocher des étapes
        // facultatives avant d'exécuter. Le client n'envoie que des CLÉS d'étapes ;
        // le filtrage s'applique ICI, au plan stocké côté serveur, et le coût est
        // ensuite recalculé sur le plan réellement retenu (on ne facture jamais
        // une étape abandonnée).
        $etapesRetenues = array_values(array_filter(
            (array) ($payload['etapes'] ?? []),
            static fn ($cle) => is_string($cle) && $cle !== '',
        ));
        if ($etapesRetenues !== []) {
            $plan = $plan->filtrerEtapes($etapesRetenues);
            if ($plan->estVide()) {
                return $this->json(['message' => 'Aucune étape retenue.'], Response::HTTP_BAD_REQUEST);
            }
        }

        $scope = new AiScope($entreprise, $invite, $conversation);

        // 2) SOLVABILITÉ (pré-vol strict) : seules les écritures sont facturées —
        // tête ET enfants de collection (source unique du chiffrage, identique au
        // budget présenté par preparer_operations).
        $facturables = [];
        foreach ($plan->operations as $op) {
            foreach ($this->mutationService->facturablesArbre($op, $scope) as $fqcn) {
                $facturables[] = $fqcn;
            }
        }
        $cout = $this->tokenAccountService->estimateWriteCost($facturables);
        $solde = $this->tokenAccountService->availableFor($entreprise);
        if ($solde < $cout) {
            return $this->json([
                'message'         => 'Solde de tokens insuffisant pour exécuter cette mission.',
                'blocked'         => true,
                'coutEstime'      => $cout,
                'soldeDisponible' => $solde,
                'buyUrl'          => $this->generateUrl('admin.token.buy'),
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        // 3) Autorisation renforcée pour les suppressions : mot de passe (jamais journalisé).
        if ($plan->contientSuppression()) {
            $password = (string) ($payload['password'] ?? '');
            if ($password === '' || !$this->passwordHasher->isPasswordValid($this->currentUser(), $password)) {
                return $this->json([
                    'message' => 'Mot de passe incorrect. La suppression n’a pas été exécutée.',
                    'blocked' => true,
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // 4) Exécution atomique + journal.
        $journal = [];
        $acteur = $this->currentUser();
        try {
            // Registre de références PARTAGÉ par tout le plan : une opération peut
            // renvoyer (« @etiquette ») à un enregistrement créé par une opération
            // précédente — c'est ce qui permet d'enchaîner plusieurs entités
            // dépendantes sous UNE seule validation.
            $refs = MutationReferences::live();
            $this->em->wrapInTransaction(function () use ($plan, $scope, $acteur, $refs, &$journal): void {
                foreach ($plan->operationsOrdonnees() as $op) {
                    $step = $this->mutationService->executer($op, $scope, $acteur, $refs);
                    $this->aplatirEtapeJournal($step, 0, $journal);
                }
            });
        } catch (InsufficientTokensException $e) {
            return $this->json([
                'message'         => 'Solde de tokens épuisé en cours d’exécution. Aucune modification n’a été conservée.',
                'blocked'         => true,
                'coutEstime'      => $cout,
                'soldeDisponible' => $this->tokenAccountService->availableFor($entreprise),
                'buyUrl'          => $this->generateUrl('admin.token.buy'),
            ], Response::HTTP_PAYMENT_REQUIRED);
        } catch (MutationException $e) {
            // Transaction annulée : les étapes déjà jouées ont été ROLLBACK.
            $journal[] = [
                'op'      => '',
                'entite'  => '',
                'libelle' => '',
                'cible'   => null,
                'statut'  => 'echec',
                'message' => $e->getMessage(),
            ];

            return $this->json([
                'success'   => false,
                'statut'    => $e->statut,
                'message'   => $e->getMessage(),
                'erreurs'   => $e->erreursChamps,
                'journal'   => $journal,
                'rolledBack' => true,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('Ket : échec d’exécution du plan de mutation.', ['exception' => $e]);

            return $this->json([
                'success'    => false,
                'message'    => 'Une erreur technique a interrompu l’exécution. Aucune modification n’a été conservée.',
                'journal'    => $journal,
                'rolledBack' => true,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Marque le plan comme exécuté (anti-rejeu) après succès, et CONSERVE le
        // journal : c'est la seule liste vraie de ce qui a été écrit. Elle est
        // réinjectée au moteur au tour suivant, pour qu'il ne puisse plus affirmer
        // qu'un enregistrement a été créé alors qu'il n'y figure pas.
        $meta['mutationPlanExecuted'] = true;
        $meta['mutationPlanJournal'] = $journal;
        $message->setMeta($meta);
        $this->em->flush();

        $reponse = [
            'success' => true,
            'message' => 'Mission exécutée avec succès.',
            'journal' => $journal,
        ];

        // ENCHAÎNEMENT DU PROGRAMME. C'est ici que se refermait le trou : jusqu'à
        // présent la réponse s'arrêtait à la ligne au-dessus, le moteur n'était
        // jamais rappelé, et une série de plans mourait donc après le premier.
        // Le plan suivant est désormais préparé par du CODE — coût nul en tokens,
        // et aucune place pour un oubli ou une recopie.
        $etape = $this->programmeEnCours->etapeDuMessage($conversation, $idMessage);
        if ($etape !== null && $etape->getProgramme() !== null) {
            $this->programmeRunner->marquerExecutee($etape, $journal);
            $reponse['programme'] = $this->suiteDuProgramme($etape->getProgramme(), $scope);
        }

        return $this->json($reponse);
    }

    /**
     * Sert l'étape suivante du programme, ou clôt la mission par son RAPPORT
     * FINAL vérifié en base. Point de passage UNIQUE : exécution, annulation
     * d'étape et interruption volontaire aboutissent tous ici, si bien qu'aucun
     * chemin ne peut laisser une série à moitié jouée sans rendre de comptes.
     *
     * @return array<string, mixed>
     */
    private function suiteDuProgramme(AssistantProgramme $programme, AiScope $scope): array
    {
        $entete = [
            'idProgramme' => (int) $programme->getId(),
            'reference'   => (string) $programme->getReference(),
        ];

        if ($programme->estEnCours()) {
            $prochaine = $this->programmeRunner->preparerProchaine($programme, $scope);
            if ($prochaine !== null) {
                return $entete + ['suivant' => $prochaine];
            }
        }

        $this->programmeEnCours->cloreSiTermine($programme);

        // Un rapport a déjà été rendu pour cette mission : ne pas en fabriquer un
        // second. Deux chemins peuvent aboutir ici pour le même programme (dernière
        // étape tranchée, puis interruption ou correction refusée) et l'utilisateur
        // se retrouverait avec deux comptes rendus concurrents du même travail.
        if ($programme->getRapport() !== null) {
            return $entete + ['rapportDejaRendu' => true];
        }

        // RAPPORT : relecture en base, ligne à ligne, de tout ce que le programme
        // prétend avoir écrit — plus les étapes qui n'ont PAS été faites. Rédigé
        // par le serveur, jamais par le modèle : c'est précisément au moment de
        // rendre compte qu'un résumé de complaisance ferait le plus de dégâts.
        $rapport = $this->programmeVerificateur->verifier($programme, $scope);
        $contenu = RapportProgramme::enMarkdown($rapport);

        $message = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu($contenu)
            ->setMeta([
                'engine'           => 'programme',
                'programme'        => $entete,
                'programmeRapport' => $rapport,
            ]);
        $programme->getConversation()?->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $entete + ['rapport' => [
            'idMessage'   => (int) $message->getId(),
            'contenu'     => $contenu,
            'conforme'    => (bool) $rapport['conforme'],
            'corrections' => count($rapport['corrections']),
        ]];
    }

    /**
     * Aplatit une étape d'exécution (tête + descendants de collection) en une liste
     * plate de lignes de journal, chacune portant son `niveau` d'indentation. Le
     * front rejoue ces lignes séquentiellement (pastilles), l'arborescence étant
     * signalée par le niveau.
     *
     * @param array<int,array> $journal (par référence)
     */
    private function aplatirEtapeJournal(array $step, int $niveau, array &$journal): void
    {
        $enfants = $step['enfants'] ?? [];
        unset($step['enfants']);
        $journal[] = $step + ['statut' => 'ok', 'niveau' => $niveau];
        foreach ($enfants as $enfant) {
            $this->aplatirEtapeJournal($enfant, $niveau + 1, $journal);
        }
    }

    /**
     * Marque un plan de mutation comme ANNULÉ (décision explicite de l'utilisateur).
     * La décision est PERSISTÉE dans la meta du message : après rechargement, le fil
     * se souvient que ce plan a été annulé (feedback permanent, plus de barre de
     * décision). Un plan déjà exécuté ne peut pas être annulé.
     */
    #[Route('/api/mutation/{idEntreprise}/{idConversation}/{idMessage}/cancel', name: 'api.mutation.cancel', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS, 'idMessage' => Requirement::DIGITS], methods: ['POST'])]
    public function cancelMutation(int $idEntreprise, int $idConversation, int $idMessage): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        $message = $this->trouverMessage($conversation, $idMessage);
        $meta = $message?->getMeta() ?? [];
        if ($message === null || !PlanEnAttente::porteUnPlan($meta)) {
            return $this->json(['message' => 'Plan introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if (PlanEnAttente::estExecute($meta)) {
            return $this->json(['message' => 'Ce plan a déjà été exécuté.'], Response::HTTP_CONFLICT);
        }

        $meta['mutationPlanCancelled'] = true;
        $message->setMeta($meta);
        $this->em->flush();

        $reponse = ['success' => true, 'message' => 'Plan annulé.'];

        // Refuser UNE étape n'annule pas la mission : la série continue, et
        // l'omission sera nommée dans le rapport final. Interrompre tout le
        // programme est une décision distincte, qui a son propre bouton.
        $etape = $this->programmeEnCours->etapeDuMessage($conversation, $idMessage);
        if ($etape !== null && $etape->getProgramme() !== null) {
            $this->programmeRunner->marquerAnnulee($etape);
            $reponse['programme'] = $this->suiteDuProgramme(
                $etape->getProgramme(),
                new AiScope($entreprise, $invite, $conversation),
            );
        }

        return $this->json($reponse);
    }

    /**
     * INTERRUPTION volontaire d'un programme : l'utilisateur stoppe la série en
     * cours de route. Les étapes non tranchées sont marquées annulées avec un
     * motif, et le rapport final est établi immédiatement — s'arrêter en chemin
     * ne doit jamais dispenser de dire ce qui a été fait et ce qui ne l'a pas été.
     */
    #[Route('/api/programme/{idEntreprise}/{idConversation}/{idProgramme}/interrompre', name: 'api.programme.interrompre', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS, 'idProgramme' => Requirement::DIGITS], methods: ['POST'])]
    public function interrompreProgramme(int $idEntreprise, int $idConversation, int $idProgramme): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        $programme = $this->programmeRepository->findDansConversation($idProgramme, $conversation);
        if ($programme === null) {
            return $this->json(['message' => 'Programme introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if (!$programme->estEnCours()) {
            return $this->json(['message' => 'Ce programme est déjà terminé.'], Response::HTTP_CONFLICT);
        }

        $this->programmeEnCours->interrompre($programme, 'Programme interrompu par l’utilisateur.');

        return $this->json([
            'success'   => true,
            'message'   => 'Programme interrompu.',
            'programme' => $this->suiteDuProgramme($programme, new AiScope($entreprise, $invite, $conversation)),
        ]);
    }

    /**
     * Lance le PROGRAMME DE CORRECTION proposé par le rapport final d'un
     * programme : mêmes étapes à valider une par une, même circuit. La liste des
     * corrections est relue depuis le rapport STOCKÉ côté serveur — le client
     * n'en transmet aucune, il ne fait que demander.
     */
    #[Route('/api/programme/{idEntreprise}/{idConversation}/{idProgramme}/corriger', name: 'api.programme.corriger', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS, 'idProgramme' => Requirement::DIGITS], methods: ['POST'])]
    public function corrigerProgramme(int $idEntreprise, int $idConversation, int $idProgramme): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }
        if ($blocage = $this->blocagePremium($entreprise)) {
            return $blocage;
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        $programme = $this->programmeRepository->findDansConversation($idProgramme, $conversation);
        $corrections = $programme?->getRapport()['corrections'] ?? [];
        if ($programme === null || !is_array($corrections) || $corrections === []) {
            return $this->json(['message' => 'Aucune correction à proposer pour ce programme.'], Response::HTTP_NOT_FOUND);
        }
        if ($this->programmeEnCours->aUnProgrammeEnCours($conversation)) {
            return $this->json([
                'message' => 'Une autre mission est déjà en cours : terminez-la avant de lancer la correction.',
            ], Response::HTTP_CONFLICT);
        }

        $scope = new AiScope($entreprise, $invite, $conversation);
        $correction = $this->programmeRunner->creer(
            $scope,
            sprintf('Corriger les écarts du programme %s', (string) $programme->getReference()),
            $corrections,
            $programme,
        );

        $prochaine = $this->programmeRunner->preparerProchaine($correction, $scope);
        if ($prochaine === null) {
            // Aucune correction n'a pu être préparée : on ne laisse pas un verrou
            // orphelin derrière nous, et on le dit.
            $this->programmeEnCours->interrompre($correction, 'Aucune étape de correction n’a pu être préparée.');

            return $this->json([
                'success'   => false,
                'message'   => 'Aucune étape de correction n’a pu être préparée.',
                'programme' => $this->suiteDuProgramme($correction, $scope),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'success'   => true,
            'message'   => 'Programme de correction lancé.',
            'programme' => [
                'idProgramme' => (int) $correction->getId(),
                'reference'   => (string) $correction->getReference(),
                'suivant'     => $prochaine,
            ],
        ]);
    }

    /**
     * Instantané des objets du contexte de la conversation au moment de l'envoi :
     * type + id + libellé (le cliché des puces telles que l'utilisateur les voit).
     * Vide → null (setContexteObjets normalise) : la bulle ne portera pas d'agrafe.
     *
     * @return array<int, array{type: string, id: int, nom: string}>
     */
    private function instantaneContexte(AssistantConversation $conversation): array
    {
        $objets = [];
        foreach ($conversation->getContextes() as $contexte) {
            $objets[] = [
                'type' => (string) $contexte->getEntityType(),
                'id'   => (int) $contexte->getEntityId(),
                'nom'  => (string) $contexte->getLabel(),
            ];
        }

        return $objets;
    }

    /**
     * Instantané des pièces jointes de la conversation à l'envoi : id + nom +
     * type + taille (cliché des puces fichiers telles que l'utilisateur les
     * voit). Vide → null (setFichiersJoints normalise) : pas d'agrafe fichiers.
     *
     * @return array<int, array{id: int, nom: string, type: string, taille: int}>
     */
    private function instantaneFichiers(AssistantConversation $conversation): array
    {
        $fichiers = [];
        foreach ($conversation->getFichiers() as $fichier) {
            $fichiers[] = [
                'id'     => (int) $fichier->getId(),
                'nom'    => (string) $fichier->getNomOriginal(),
                'type'   => (string) ($fichier->getMimeType() ?: 'inconnu'),
                'taille' => $fichier->getTaille(),
            ];
        }

        return $fichiers;
    }

    /**
     * Attache un lot d'objets du workspace au contexte de la conversation
     * (sélection des listes → « Ajouter au chat avec l'assistant IA »).
     * FAIL-CLOSED par objet : whitelist de la carte de permissions + canRead
     * selon le rôle de l'invité + scoping entreprise — un objet invalide est
     * ignoré (compteur `ignores`), pas de 403 global (sélection hétérogène).
     * FACTURATION : chaque objet réellement attaché coûte 80 % du poids d'un
     * message IA, métré en une fois AVANT persistance (402 si solde épuisé,
     * rien n'est attaché). Idempotent sur les doublons (aucun débit).
     */
    #[Route('/api/contextes/{idEntreprise}/{idConversation}', name: 'api.contexte.attach', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS], methods: ['POST'])]
    public function attachContextes(int $idEntreprise, int $idConversation, Request $request): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }
        if ($blocage = $this->blocagePremium($entreprise)) {
            return $blocage;
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        $payload = json_decode($request->getContent(), true) ?: [];
        $objets = $payload['objets'] ?? null;
        if (!\is_array($objets) || $objets === []) {
            return $this->json(['message' => 'Aucun objet fourni.'], Response::HTTP_BAD_REQUEST);
        }

        // 1) Validation de TOUT le lot avant le moindre débit ou persistance.
        $labels = $this->accessResolver->libellesEntites();
        $ignores = 0;
        $aAttacher = [];
        foreach ($objets as $objet) {
            $type = (string) ($objet['type'] ?? '');
            $id = (int) ($objet['id'] ?? 0);
            $fqcn = 'App\\Entity\\' . $type;
            if (!isset($labels[$type]) || !class_exists($fqcn) || $id <= 0
                || !$this->accessResolver->canRead($invite, $type)) {
                $ignores++;
                continue;
            }
            if (isset($aAttacher[$type . '#' . $id]) || $conversation->hasContexte($type, $id)) {
                continue; // Déjà attaché (ou doublon du lot) : idempotent, aucun débit.
            }
            // Scoping : l'enregistrement doit exister DANS cette entreprise.
            $result = $this->searchService->search($fqcn, ['id' => $id], $entreprise, null, 1, 1);
            $entity = $result['data'][0] ?? null;
            if (($result['status']['code'] ?? 500) !== 200 || $entity === null) {
                $ignores++;
                continue;
            }
            if (\count($conversation->getContextes()) + \count($aAttacher) >= self::MAX_CONTEXTES) {
                $ignores++;
                continue;
            }
            $aAttacher[$type . '#' . $id] = [$type, $id, $entity];
        }

        // 2) MÉTRAGE avant persistance (patron sendMessage) : solde épuisé →
        // 402, rien n'est attaché.
        try {
            $this->tokenAccountService->meterContexteIa($entreprise, $this->currentUser(), \count($aAttacher));
        } catch (InsufficientTokensException $e) {
            return $this->json([
                'message'       => 'Quota de tokens épuisé. Rechargez votre solde ou attendez le renouvellement de votre allocation gratuite.',
                'blocked'       => true,
                'required'      => $e->required,
                'available'     => $e->available,
                'nextRenewalAt' => $e->nextRenewalAt?->format(\DateTimeImmutable::ATOM),
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        // 3) Persistance, avec un instantané du libellé pour l'affichage.
        foreach ($aAttacher as [$type, $id, $entity]) {
            $displayField = $this->libelleur->displayField('App\\Entity\\' . $type);
            $conversation->addContexte((new AssistantConversationContexte())
                ->setEntityType($type)
                ->setEntityId($id)
                ->setLabel(mb_substr($this->libelleur->libelle($entity, $displayField), 0, 160)));
        }
        if ($aAttacher !== []) {
            $this->em->flush();
        }

        return $this->reponseContextes($conversation, $ignores);
    }

    /** Retire UN objet du contexte de la conversation (par id de rattachement). */
    #[Route('/api/contextes/{idEntreprise}/{idConversation}/{idContexte}', name: 'api.contexte.detach', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS, 'idContexte' => Requirement::DIGITS], methods: ['DELETE'])]
    public function detachContexte(int $idEntreprise, int $idConversation, int $idContexte): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        // Recherche DANS la collection de la conversation (jamais par id global) :
        // un id d'une autre conversation est simplement introuvable ici.
        $contexte = null;
        foreach ($conversation->getContextes() as $candidat) {
            if ($candidat->getId() === $idContexte) {
                $contexte = $candidat;
                break;
            }
        }
        if ($contexte === null) {
            return $this->json(['message' => 'Objet de contexte introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $conversation->removeContexte($contexte); // orphanRemoval → suppression
        $this->em->flush();

        return $this->reponseContextes($conversation);
    }

    /** Vide le contexte de la conversation (tous les objets d'un coup). */
    #[Route('/api/contextes/{idEntreprise}/{idConversation}', name: 'api.contexte.clear', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS], methods: ['DELETE'])]
    public function clearContextes(int $idEntreprise, int $idConversation): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        foreach ($conversation->getContextes()->toArray() as $contexte) {
            $conversation->removeContexte($contexte);
        }
        $this->em->flush();

        return $this->reponseContextes($conversation);
    }

    /**
     * Réponse commune des endpoints contexte : liste sérialisée + fragment HTML
     * des puces re-rendu côté serveur (chemin de rendu unique, partagé avec le
     * rendu initial du chat).
     */
    private function reponseContextes(AssistantConversation $conversation, int $ignores = 0): JsonResponse
    {
        return $this->json([
            'contextes' => array_values(array_map(
                static fn (AssistantConversationContexte $c) => [
                    'id'         => $c->getId(),
                    'entityType' => $c->getEntityType(),
                    'entityId'   => $c->getEntityId(),
                    'label'      => $c->getLabel(),
                ],
                $conversation->getContextes()->toArray(),
            )),
            'html'    => $this->renderView('components/_assistant_ia_chat_contextes.html.twig', [
                'conversation'    => $conversation,
                'fichesContextes' => $this->fichesContextes($conversation),
            ]),
            'ignores' => $ignores,
        ]);
    }

    /**
     * Fiches des objets attachés, indexées « Type#id » pour les infobulles des
     * puces : EXACTEMENT ce que l'assistant capture dans son contexte (même
     * source que le prompt — AiContextBuilder, re-validation fail-closed).
     * Un objet devenu introuvable/inaccessible n'a simplement pas de fiche.
     */
    private function fichesContextes(AssistantConversation $conversation): array
    {
        $fiches = [];
        foreach ($this->contextBuilder->objetsAttaches(
            $conversation,
            $conversation->getEntreprise(),
            $conversation->getInvite(),
        ) as $objet) {
            $fiches[$objet['type'] . '#' . $objet['id']] = $objet['fiche'];
        }

        return $fiches;
    }

    /**
     * Attache un lot de FICHIERS (multipart) au contexte de la conversation.
     * Miroir de attachContextes, côté pièces jointes : validation de TOUT le lot
     * AVANT le moindre débit (politique unique FichierAttachePolicy : taille ≤
     * 10 Mo, formats autorisés, plafond par conversation), MÉTRAGE (100 % du poids
     * message par fichier) → 402 si solde épuisé, puis stockage Vich (dossier
     * privé) + extraction de texte best-effort. Un fichier invalide est ignoré
     * (compteur `ignores` + message dans `erreurs`), jamais de 403 global.
     */
    #[Route('/api/fichiers/{idEntreprise}/{idConversation}', name: 'api.fichier.attach', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS], methods: ['POST'])]
    public function attachFichiers(int $idEntreprise, int $idConversation, Request $request): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }
        if ($blocage = $this->blocagePremium($entreprise)) {
            return $blocage;
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        /** @var UploadedFile[] $fichiers */
        $fichiers = $request->files->all()['fichiers'] ?? [];
        if (!\is_array($fichiers)) {
            $fichiers = [$fichiers];
        }
        $fichiers = array_values(array_filter($fichiers, static fn ($f) => $f instanceof UploadedFile));
        if ($fichiers === []) {
            return $this->json(['message' => 'Aucun fichier fourni.'], Response::HTTP_BAD_REQUEST);
        }

        // 1) Validation de TOUT le lot avant le moindre débit ou stockage.
        $contrainte = FichierAttachePolicy::contrainte();
        $ignores = 0;
        $erreurs = [];
        $aStocker = [];
        $dejaAttaches = $conversation->nbFichiers();
        foreach ($fichiers as $fichier) {
            if ($dejaAttaches + \count($aStocker) >= FichierAttachePolicy::MAX_FILES) {
                $ignores++;
                $erreurs[] = sprintf('Limite de %d fichiers par conversation atteinte.', FichierAttachePolicy::MAX_FILES);
                continue;
            }
            $violations = $this->validator->validate($fichier, $contrainte);
            if (\count($violations) > 0) {
                $ignores++;
                $erreurs[] = (string) $violations[0]->getMessage();
                continue;
            }
            $aStocker[] = $fichier;
        }

        // 2) MÉTRAGE avant stockage (patron attachContextes) : solde épuisé → 402.
        try {
            $this->tokenAccountService->meterFichierIa($entreprise, $this->currentUser(), \count($aStocker));
        } catch (InsufficientTokensException $e) {
            return $this->json([
                'message'       => 'Quota de tokens épuisé. Rechargez votre solde ou attendez le renouvellement de votre allocation gratuite.',
                'blocked'       => true,
                'required'      => $e->required,
                'available'     => $e->available,
                'nextRenewalAt' => $e->nextRenewalAt?->format(\DateTimeImmutable::ATOM),
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        // 3) Stockage : l'extraction de texte LIT le fichier temporaire AVANT le
        // flush (Vich déplace ensuite le binaire vers le dossier privé).
        foreach ($aStocker as $fichier) {
            $entite = (new AssistantConversationFichier())
                ->setNomOriginal(mb_substr($fichier->getClientOriginalName(), 0, 255))
                ->setFichier($fichier)
                ->setMimeType(mb_substr((string) ($fichier->getMimeType() ?: $fichier->getClientMimeType()), 0, 120))
                ->setTaille((int) $fichier->getSize())
                ->setTexteExtrait($this->fichierExtracteur->extraire($fichier));
            $conversation->addFichier($entite);
        }
        if ($aStocker !== []) {
            $this->em->flush();
        }

        return $this->reponseFichiers($conversation, $ignores, $erreurs);
    }

    /** Retire UN fichier du contexte de la conversation (par id de rattachement). */
    #[Route('/api/fichiers/{idEntreprise}/{idConversation}/{idFichier}', name: 'api.fichier.detach', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS, 'idFichier' => Requirement::DIGITS], methods: ['DELETE'])]
    public function detachFichier(int $idEntreprise, int $idConversation, int $idFichier): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        $fichier = $this->trouverFichier($conversation, $idFichier);
        if ($fichier === null) {
            return $this->json(['message' => 'Fichier introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $conversation->removeFichier($fichier); // orphanRemoval → suppression + Vich retire le binaire
        $this->em->flush();

        return $this->reponseFichiers($conversation);
    }

    /** Vide les pièces jointes de la conversation (tous les fichiers d'un coup). */
    #[Route('/api/fichiers/{idEntreprise}/{idConversation}', name: 'api.fichier.clear', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS], methods: ['DELETE'])]
    public function clearFichiers(int $idEntreprise, int $idConversation): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        foreach ($conversation->getFichiers()->toArray() as $fichier) {
            $conversation->removeFichier($fichier);
        }
        $this->em->flush();

        return $this->reponseFichiers($conversation);
    }

    /**
     * Télécharge une pièce jointe — FAIL-CLOSED : le fichier doit appartenir à
     * une conversation de l'entreprise ET de l'invité courants (le DownloadHandler
     * Vich ne s'occupe que du streaming, jamais du contrôle d'accès).
     */
    #[Route('/api/fichiers/{idEntreprise}/{idConversation}/{idFichier}/download', name: 'api.fichier.download', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS, 'idFichier' => Requirement::DIGITS], methods: ['GET'])]
    public function downloadFichier(int $idEntreprise, int $idConversation, int $idFichier, DownloadHandler $downloadHandler): Response
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        $fichier = $this->trouverFichier($conversation, $idFichier);
        if ($fichier === null) {
            throw $this->createNotFoundException('Fichier introuvable.');
        }

        return $downloadHandler->downloadObject($fichier, 'fichier', null, $fichier->getNomOriginal());
    }

    /**
     * Télécharge un DOCUMENT enregistré en base (entité Document) — FAIL-CLOSED :
     * module IA autorisé + droit de LECTURE sur Document + le document doit
     * exister DANS l'entreprise de l'invité (scoping searchService) et porter un
     * fichier physique. Permet à Ket de proposer le téléchargement d'un document
     * de la base (pas seulement des pièces jointes de la conversation).
     */
    #[Route('/api/documents/{idEntreprise}/{idDocument}/download', name: 'api.document.download', requirements: ['idEntreprise' => Requirement::DIGITS, 'idDocument' => Requirement::DIGITS], methods: ['GET'])]
    public function downloadDocument(int $idEntreprise, int $idDocument, DownloadHandler $downloadHandler): Response
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite) || !$this->accessResolver->canRead($invite, 'Document')) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }

        $result = $this->searchService->search('App\\Entity\\Document', ['id' => $idDocument], $entreprise, null, 1, 1);
        $document = $result['data'][0] ?? null;
        if (($result['status']['code'] ?? 500) !== 200 || $document === null || $document->getNomFichierStocke() === null) {
            throw $this->createNotFoundException('Document introuvable ou sans fichier.');
        }

        // Le libellé (« nom ») du Document n'a pas d'extension → le fichier téléchargé
        // serait inouvrable. On restitue l'extension RÉELLE (depuis le nom de stockage
        // Vich, qui préserve l'extension d'origine via SmartUniqueNamer).
        $nomTelechargement = $this->nomAvecExtension((string) $document->getNom(), (string) $document->getNomFichierStocke());

        return $downloadHandler->downloadObject($document, 'fichier', null, $nomTelechargement);
    }

    /**
     * Exporte UN message du fil en document téléchargeable (PDF, Word, Markdown).
     *
     * FAIL-CLOSED par construction : le message est cherché DANS la conversation,
     * elle-même déjà restreinte à cet invité et à cette entreprise par
     * requireConversation(). Un idMessage d'un autre invité est donc un 404.
     *
     * `format` est contraint par la ROUTE : toute autre valeur est rejetée par le
     * routeur avant d'entrer ici (404), donc MessageExporter::FORMATS reste la
     * seule liste à maintenir.
     *
     * NON MÉTRÉ, délibérément : le message a déjà été facturé à l'envoi
     * (meterWrite), l'export ne lit aucune entité supplémentaire et n'appelle pas
     * le moteur. Facturer la relecture de son propre message serait une double
     * facturation. Même parti pris que SoaController::envoyer() et que la bascule
     * de thème. L'accès reste gardé (module + premium) ; c'est la consommation
     * qui ne l'est pas.
     */
    #[Route('/api/messages/{idEntreprise}/{idConversation}/{idMessage}/export/{format}', name: 'api.message.export', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS, 'idMessage' => Requirement::DIGITS, 'format' => 'pdf|word|markdown'], methods: ['GET'])]
    public function exportMessage(
        int $idEntreprise,
        int $idConversation,
        int $idMessage,
        string $format,
        MessageExporter $exporter,
    ): Response {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }
        if ($blocage = $this->blocagePremium($entreprise)) {
            return $blocage;
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        $message = $this->trouverMessage($conversation, $idMessage);
        if ($message === null) {
            throw $this->createNotFoundException('Message introuvable.');
        }

        $fichier = $exporter->exporter($message, $format, $entreprise, $this->parametresRepository->nomPour($entreprise));

        return new Response($fichier->contenu, Response::HTTP_OK, [
            'Content-Type' => $fichier->mime,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $fichier->nomFichier
            ),
        ]);
    }

    /**
     * Picker de destinataires d'un message (fragment HTML, comme les autres
     * pickers de l'application).
     */
    #[Route('/api/messages/{idEntreprise}/{idConversation}/{idMessage}/destinataires', name: 'api.message.destinataires', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS, 'idMessage' => Requirement::DIGITS], methods: ['GET'])]
    public function destinatairesMessage(
        int $idEntreprise,
        int $idConversation,
        int $idMessage,
        MessageDestinataires $destinataires,
    ): Response {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }
        if ($blocage = $this->blocagePremium($entreprise)) {
            return $blocage;
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);
        $message = $this->trouverMessage($conversation, $idMessage);
        if ($message === null) {
            throw $this->createNotFoundException('Message introuvable.');
        }

        $carnet = $destinataires->collecter($entreprise, $invite, $this->currentUser());

        return $this->render('components/assistant_ia/_message_destinataire_picker.html.twig', [
            'message' => $message,
            'assistantNom' => $this->parametresRepository->nomPour($entreprise),
            'destinataires' => $carnet['destinataires'],
            'tronque' => $carnet['tronque'],
            'categorieLabels' => MessageDestinataires::CATEGORIE_LABELS,
            'urlEnvoi' => $this->generateUrl('admin.assistantia.api.message.envoyer', [
                'idEntreprise' => $idEntreprise,
                'idConversation' => $idConversation,
                'idMessage' => $idMessage,
            ]),
        ]);
    }

    /**
     * Envoie UN message du fil par e-mail à UN OU PLUSIEURS destinataires, pris
     * dans le carnet et/ou saisis à la main.
     *
     * UN E-MAIL PAR DESTINATAIRE, jamais un « À » collectif : les correspondants
     * d'un courtier (contact d'un client, assureur, co-courtier) ne doivent pas
     * découvrir mutuellement leurs adresses. Chaque envoi porte donc sa propre
     * salutation et sa propre ligne de trace.
     *
     * Toutes les adresses sont résolues et validées AVANT le premier envoi : une
     * faute de frappe sur la troisième adresse ne doit pas laisser partir les
     * deux premières.
     *
     * Une adresse HORS CARNET est acceptée — c'est un besoin réel — mais jamais
     * sans contrôle, parce que l'e-mail part sous la marque JS Brokers vers un
     * tiers : format validé, plafond par message, marquage `horsCarnet` dans la
     * trace et journalisation nominative. Le `replyTo` porte l'adresse du
     * courtier, de sorte que la réponse lui revienne directement.
     *
     * NON MÉTRÉ (même parti pris que l'export et que SoaController::envoyer).
     */
    #[Route('/api/messages/{idEntreprise}/{idConversation}/{idMessage}/envoyer', name: 'api.message.envoyer', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS, 'idMessage' => Requirement::DIGITS], methods: ['POST'])]
    public function envoyerMessage(
        int $idEntreprise,
        int $idConversation,
        int $idMessage,
        Request $request,
        MessageDestinataires $destinataires,
        MessageMailNotifier $notifier,
        ImageJointeValidator $imageValidator,
    ): JsonResponse {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }
        if ($blocage = $this->blocagePremium($entreprise)) {
            return $blocage;
        }
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);
        $message = $this->trouverMessage($conversation, $idMessage);
        if ($message === null) {
            return $this->json(['message' => 'Message introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true) ?: [];
        $acteur = $this->currentUser();
        $demandees = $this->adressesDemandees($payload);
        if ($demandees === []) {
            return $this->json([
                'message' => 'Sélectionnez au moins un destinataire, ou saisissez une adresse.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $meta = $message->getMeta() ?? [];
        $envois = is_array($meta['envois'] ?? null) ? $meta['envois'] : [];
        // Le plafond porte sur le CUMUL : cinq destinataires consomment cinq
        // envois. Vérifié avant tout départ, pour ne pas en laisser partir une
        // partie puis refuser le reste.
        if (\count($envois) + \count($demandees) > self::MAX_ENVOIS_PAR_MESSAGE) {
            return $this->json([
                'message' => sprintf(
                    'Ce message a déjà été envoyé %d fois (plafond : %d). Réexportez-le si vous devez le diffuser davantage.',
                    \count($envois),
                    self::MAX_ENVOIS_PAR_MESSAGE
                ),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Résolution + validation de TOUTES les adresses avant le premier envoi.
        $connus = $destinataires->trouverPlusieurs($entreprise, $invite, $acteur, $demandees);
        $cibles = [];
        foreach ($demandees as $email) {
            $destinataire = $connus[mb_strtolower($email)] ?? null;
            if ($destinataire === null) {
                $erreurs = $this->validator->validate($email, [
                    new Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT, message: 'Adresse e-mail invalide.'),
                ]);
                if (\count($erreurs) > 0) {
                    return $this->json([
                        'message' => sprintf('Adresse e-mail invalide : %s', $email),
                    ], Response::HTTP_BAD_REQUEST);
                }
                $destinataire = ['email' => $email, 'nom' => $email, 'detail' => 'Adresse saisie', 'horsCarnet' => true];
                // Un envoi hors carnet doit rester retrouvable : il engage la marque.
                $this->logger->notice('Assistant IA : envoi d\'un message à une adresse hors carnet.', [
                    'entreprise' => $entreprise->getId(),
                    'invite' => $invite?->getId(),
                    'message' => $message->getId(),
                    'email' => $email,
                ]);
            }
            $cibles[] = $destinataire;
        }

        // Format « image » : la pièce est une CAPTURE produite par le navigateur —
        // seule façon d'obtenir un rendu fidèle (les graphiques Chart.js vivent
        // dans un <canvas>). C'est le seul endroit où l'application accepte un
        // binaire fabriqué par le client, et il n'est jamais réexpédié tel quel :
        // ImageJointeValidator le reconstruit à partir de ses seuls pixels.
        $format = $payload['format'] ?? null;
        $pieceFournie = null;
        if ($format === MessageMailNotifier::FORMAT_IMAGE) {
            try {
                $pieceFournie = $imageValidator->valider((string) ($payload['image'] ?? ''), (int) $message->getId());
            } catch (\InvalidArgumentException $e) {
                return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }
        } else {
            $format = in_array($format, MessageExporter::FORMATS, true) ? $format : null;
        }

        $assistantNom = $this->parametresRepository->nomPour($entreprise);
        $accompagnement = (string) ($payload['message'] ?? '');

        $reussis = [];
        $echecs = [];
        foreach ($cibles as $cible) {
            if ($notifier->envoyer($message, $entreprise, $assistantNom, $cible, $acteur, $format, $accompagnement, $pieceFournie)) {
                $reussis[] = $cible;
            } else {
                $echecs[] = $cible['email'];
            }
        }

        if ($reussis === []) {
            return $this->json([
                'success' => false,
                'message' => "L'e-mail n'a pas pu être envoyé. Réessayez dans un instant.",
            ], Response::HTTP_OK);
        }

        // Trace attachée AU MESSAGE : elle voyage avec la conversation (purge
        // automatique en cascade), sans table ni migration supplémentaire.
        // Une ligne PAR destinataire réellement servi.
        $horodatage = (new \DateTimeImmutable('now'))->format(\DateTimeImmutable::ATOM);
        foreach ($reussis as $cible) {
            $envois[] = [
                'email' => $cible['email'],
                'nom' => $cible['nom'],
                'detail' => $cible['detail'],
                'format' => $format,
                'at' => $horodatage,
                'invite' => $invite?->getId(),
                'horsCarnet' => ($cible['horsCarnet'] ?? false) === true,
            ];
        }
        $meta['envois'] = $envois;
        $message->setMeta($meta);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => $this->messageEnvoi($reussis, $echecs),
        ]);
    }

    /**
     * Adresses réellement demandées : sélection du carnet + adresse(s) saisies,
     * nettoyées et dédoublonnées (insensible à la casse). L'ordre de première
     * apparition est conservé — c'est celui du carnet à l'écran.
     *
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function adressesDemandees(array $payload): array
    {
        $brutes = $payload['emails'] ?? [];
        if (!is_array($brutes)) {
            $brutes = [];
        }
        // `email` (singulier) reste accepté : un appel plus ancien, ou un envoi à
        // un seul destinataire, n'a pas à connaître la forme tableau.
        if (isset($payload['email'])) {
            $brutes[] = $payload['email'];
        }

        $adresses = [];
        $vues = [];
        foreach ($brutes as $brute) {
            if (!is_scalar($brute)) {
                continue;
            }
            $email = trim((string) $brute);
            $cle = mb_strtolower($email);
            if ($email === '' || isset($vues[$cle])) {
                continue;
            }
            $vues[$cle] = true;
            $adresses[] = $email;
        }

        return $adresses;
    }

    /**
     * Compte rendu d'un envoi multiple : ce qui est parti, et ce qui a échoué —
     * un échec partiel ne doit pas se cacher derrière un « message envoyé ».
     *
     * @param array<int, array{email: string}> $reussis
     * @param array<int, string> $echecs
     */
    private function messageEnvoi(array $reussis, array $echecs): string
    {
        $compte = \count($reussis);
        $texte = $compte === 1
            ? sprintf('Message envoyé à %s.', $reussis[0]['email'])
            : sprintf('Message envoyé à %d destinataires.', $compte);

        if ($echecs !== []) {
            $texte .= sprintf(' Échec pour : %s.', implode(', ', $echecs));
        }

        return $texte;
    }

    /** Ajoute au libellé l'extension du nom de stockage si elle manque (fichier ouvrable). */
    private function nomAvecExtension(string $nom, string $nomStocke): string
    {
        $ext = strtolower(pathinfo($nomStocke, PATHINFO_EXTENSION));
        $nom = trim($nom) !== '' ? trim($nom) : 'document';
        if ($ext !== '' && !str_ends_with(strtolower($nom), '.' . $ext)) {
            $nom .= '.' . $ext;
        }

        return $nom;
    }

    /** Cherche un fichier DANS la collection de la conversation (jamais par id global). */
    private function trouverFichier(AssistantConversation $conversation, int $idFichier): ?AssistantConversationFichier
    {
        foreach ($conversation->getFichiers() as $candidat) {
            if ($candidat->getId() === $idFichier) {
                return $candidat;
            }
        }

        return null;
    }

    /**
     * Réponse commune des endpoints fichiers : liste sérialisée + fragment HTML
     * des puces re-rendu côté serveur (chemin de rendu unique, partagé avec le
     * rendu initial du chat).
     *
     * @param string[] $erreurs
     */
    private function reponseFichiers(AssistantConversation $conversation, int $ignores = 0, array $erreurs = []): JsonResponse
    {
        return $this->json([
            'fichiers' => array_values(array_map(
                static fn (AssistantConversationFichier $f) => [
                    'id'     => $f->getId(),
                    'nom'    => $f->getNomOriginal(),
                    'type'   => $f->getMimeType(),
                    'taille' => $f->getTaille(),
                    'aExtrait' => $f->getTexteExtrait() !== null,
                ],
                $conversation->getFichiers()->toArray(),
            )),
            'html'    => $this->renderView('components/_assistant_ia_chat_fichiers.html.twig', [
                'conversation' => $conversation,
                'idEntreprise' => $conversation->getEntreprise()?->getId(),
            ]),
            'ignores' => $ignores,
            'erreurs' => array_values(array_unique($erreurs)),
        ]);
    }

    /**
     * Contexte d'ouverture de dialogue demandé par une ACTION de l'assistant
     * (directive uiAction 'open-dialog') : entité normalisée + canevas de
     * formulaire, patron AvenantController::getPisteDeriveeContext. La
     * directive émise par l'outil n'est PAS une autorisation : cet endpoint
     * re-valide tout (fail-closed) car c'est une requête HTTP distincte.
     */
    #[Route('/api/dialog-context/{idEntreprise}', name: 'api.dialog_context', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function dialogContext(int $idEntreprise, Request $request): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        $shortName = (string) $request->query->get('entite', '');
        $mode = (string) $request->query->get('mode', 'creation');
        $id = (int) $request->query->get('id', 0);

        $labels = $this->accessResolver->libellesEntites();
        $fqcn = 'App\\Entity\\' . $shortName;
        if (!isset($labels[$shortName]) || !class_exists($fqcn) || !in_array($mode, ['creation', 'edition'], true)) {
            return $this->json(['message' => 'Demande invalide.'], Response::HTTP_BAD_REQUEST);
        }

        // FAIL-CLOSED : mêmes niveaux que l'outil ouvrir_dialogue — Écriture en
        // création, Modification en édition.
        $level = $mode === 'edition' ? Invite::ACCESS_MODIFICATION : Invite::ACCESS_ECRITURE;
        if (!$this->accessResolver->can($invite, $shortName, $level)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        $entity = null;
        if ($mode === 'edition') {
            // Scoping : l'enregistrement doit exister DANS cette entreprise.
            $result = $this->searchService->search($fqcn, ['id' => $id], $entreprise, null, 1, 1);
            $entity = $result['data'][0] ?? null;
            if (($result['status']['code'] ?? 500) !== 200 || $entity === null) {
                return $this->json(['message' => 'Enregistrement introuvable.'], Response::HTTP_NOT_FOUND);
            }
        }

        // Pré-remplissage (création uniquement) : la proposition venue du front
        // n'est JAMAIS posée telle quelle dans le DOM — seule cette réponse,
        // whitelistée (champs scalaires mappés, plafonds), fait foi.
        $prefill = [];
        if ($mode === 'creation') {
            $brut = json_decode((string) $request->query->get('valeurs', ''), true);
            $prefill = \is_array($brut) ? $this->prefillWhitelist->filtrer($fqcn, $brut) : [];
        }

        return $this->json([
            'mode'       => $mode,
            'entite'     => $shortName,
            'entity'     => $entity !== null ? $this->normalizer->normalize($entity, null, ['groups' => ['list:read']]) : null,
            'formCanvas' => $this->canvasBuilder->getEntityFormCanvas($entity ?? new $fqcn(), $idEntreprise),
            'prefill'    => $prefill ?: null,
        ]);
    }

    /**
     * Contexte de VISUALISATION demandé par une action de l'assistant (directive
     * 'open-visualization') : entité normalisée (valeurs calculées chargées) +
     * canvas d'entité — le payload attendu par `app:liste-element:openned`
     * (même circuit que l'ouverture depuis une liste). Re-validation fail-closed
     * complète : la directive n'est pas une autorisation.
     */
    #[Route('/api/visual-context/{idEntreprise}', name: 'api.visual_context', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function visualContext(int $idEntreprise, Request $request): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if (!$this->moduleAutorise($invite)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        $shortName = (string) $request->query->get('entite', '');
        $id = (int) $request->query->get('id', 0);

        $labels = $this->accessResolver->libellesEntites();
        $fqcn = 'App\\Entity\\' . $shortName;
        if (!isset($labels[$shortName]) || !class_exists($fqcn) || $id <= 0) {
            return $this->json(['message' => 'Demande invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$this->accessResolver->canRead($invite, $shortName)) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        // Scoping : l'enregistrement doit exister DANS cette entreprise.
        $result = $this->searchService->search($fqcn, ['id' => $id], $entreprise, null, 1, 1);
        $entity = $result['data'][0] ?? null;
        if (($result['status']['code'] ?? 500) !== 200 || $entity === null) {
            return $this->json(['message' => 'Enregistrement introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // Même contenu qu'une ligne de liste : attributs sérialisés + valeurs
        // CALCULÉES fusionnées (hors groupes list:read — on les ajoute depuis le
        // canvas, comme le font les listes du workspace) + relations du canevas que
        // la sérialisation ne publie pas (cf. CanvasRelationHydrator) : sans elles,
        // l'accordéon affichait « N/A » sur l'assureur, le client ou le risque.
        $this->canvasBuilder->loadAllCalculatedValues($entity);
        $entityCanvas = $this->canvasBuilder->getEntityCanvas($fqcn);
        $normalized = $this->relationHydrator->payload($entity, $entityCanvas);
        foreach (($entityCanvas['liste'] ?? []) as $fieldDef) {
            $code = (string) ($fieldDef['code'] ?? '');
            if ($code !== '' && !array_key_exists($code, $normalized) && isset($entity->{$code})) {
                $normalized[$code] = $entity->{$code};
            }
        }

        return $this->json([
            'entityType'   => $shortName,
            'entity'       => $normalized,
            'entityCanvas' => $entityCanvas,
        ]);
    }

    /**
     * L'invité a-t-il accès au MODULE Assistant IA ? Pseudo-entité « AssistantIa »
     * de la carte de permissions (RolesEnAdministration::accessAssistantIa) —
     * fail-closed pour les invités, accès total inconditionnel du propriétaire.
     */
    private function moduleAutorise(?Invite $invite): bool
    {
        return $invite !== null && $this->accessResolver->canRead($invite, 'AssistantIa');
    }

    /** Panneau « fonctionnalité premium » (compte sans solde payant), col-3 ou col-4. */
    private function renderPremium(Entreprise $entreprise, Invite $invite): Response
    {
        return $this->render('components/_assistant_ia_premium.html.twig', [
            'assistantNom'    => $this->parametresRepository->nomPour($entreprise),
            'entrepriseNom'   => (string) $entreprise->getNom(),
            // Seul le propriétaire peut acheter des tokens : le CTA lui est réservé.
            'estProprietaire' => $entreprise->getUtilisateur()?->getId() === $invite->getUtilisateur()?->getId(),
        ]);
    }

    /** Blocage JSON des APIs quand le compte n'a pas de solde payant (402 premium). */
    private function blocagePremium(Entreprise $entreprise): ?JsonResponse
    {
        if ($this->tokenAccountService->estComptePayant($entreprise)) {
            return null;
        }

        return $this->json([
            'message' => "L'assistant IA est réservé aux comptes disposant d'un solde de tokens "
                . 'payant. Rechargez votre solde pour l\'activer.',
            'blocked' => true,
            'premium' => true,
        ], Response::HTTP_PAYMENT_REQUIRED);
    }

    /**
     * Charge l'entreprise (404 sinon) et résout l'invité connecté, en refusant
     * tout invité rattaché à une AUTRE entreprise que celle demandée.
     *
     * @return array{0: Entreprise, 1: ?Invite}
     */
    private function resolveWorkspace(int $idEntreprise): array
    {
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        if (!$entreprise instanceof Entreprise) {
            throw $this->createNotFoundException('Entreprise introuvable.');
        }

        $invite = $this->accessResolver->resolveConnectedInvite($this->currentUser());
        if ($invite !== null && $invite->getEntreprise()?->getId() !== $entreprise->getId()) {
            $invite = null;
        }

        return [$entreprise, $invite];
    }

    /** Conversation appartenant à CET invité dans CETTE entreprise, sinon 404. */
    private function requireConversation(int $idConversation, ?Invite $invite, Entreprise $entreprise): AssistantConversation
    {
        if ($invite === null) {
            throw $this->createNotFoundException('Conversation introuvable.');
        }

        return $this->conversationRepository->findOneDeLInvite($idConversation, $invite, $entreprise)
            ?? throw $this->createNotFoundException('Conversation introuvable.');
    }

    /**
     * Message appartenant à CETTE conversation, sinon null.
     *
     * Le parcours de la collection (et non un AssistantMessageRepository::find())
     * EST la garantie d'appartenance : $conversation sort déjà de
     * findOneDeLInvite(), donc un id d'un autre invité ou d'une autre entreprise
     * ne peut pas être atteint. AssistantConversation::$messages porte
     * #[ORM\OrderBy(['id' => 'ASC'])] — parcours déterministe.
     *
     * Retourne null plutôt que de lever : les appelants divergent sur la réponse
     * d'échec (JSON 404 pour les mutations, createNotFoundException pour les
     * routes de navigation).
     */
    private function trouverMessage(AssistantConversation $conversation, int $idMessage): ?AssistantMessage
    {
        foreach ($conversation->getMessages() as $message) {
            if ($message->getId() === $idMessage) {
                return $message;
            }
        }

        return null;
    }

    private function currentUser(): Utilisateur
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $user;
    }
}
