<?php

namespace App\Controller\Admin;

use App\Ai\AiContextBuilder;
use App\Ai\Engine\AiEngineInterface;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\AssistantParametres;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Repository\AssistantConversationRepository;
use App\Repository\AssistantParametresRepository;
use App\Repository\EntrepriseRepository;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Token\InsufficientTokensException;
use App\Token\TokenAccountService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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

    public function __construct(
        private EntrepriseRepository $entrepriseRepository,
        private AssistantConversationRepository $conversationRepository,
        private AssistantParametresRepository $parametresRepository,
        private WorkspaceAccessResolver $accessResolver,
        private TokenAccountService $tokenAccountService,
        private AiContextBuilder $contextBuilder,
        private AiEngineInterface $aiEngine,
        private EntityManagerInterface $em,
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
        if ($invite === null) {
            return $this->render('components/_access_denied.html.twig', ['entiteNom' => 'Assistant IA']);
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
            'assistantNom' => $parametres?->getNom() ?? AssistantParametres::NOM_PAR_DEFAUT,
            'nomParDefaut' => AssistantParametres::NOM_PAR_DEFAUT,
            'idEntreprise' => $idEntreprise,
            'enregistre'   => $enregistre,
            'erreur'       => $erreur,
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
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        return $this->render('components/_assistant_ia_chat.html.twig', [
            'conversation'  => $conversation,
            'assistantNom'  => $this->parametresRepository->nomPour($entreprise),
            'entreprise'    => $entreprise,
            'idEntreprise'  => $idEntreprise,
        ]);
    }

    /** Crée une conversation vide pour l'invité courant. */
    #[Route('/api/conversations/{idEntreprise}', name: 'api.conversation.create', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['POST'])]
    public function createConversation(int $idEntreprise): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        if ($invite === null) {
            return $this->json(['message' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
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

    /** Supprime une conversation de l'invité (messages en cascade). */
    #[Route('/api/conversations/{idEntreprise}/{idConversation}', name: 'api.conversation.delete', requirements: ['idEntreprise' => Requirement::DIGITS, 'idConversation' => Requirement::DIGITS], methods: ['DELETE'])]
    public function deleteConversation(int $idEntreprise, int $idConversation): JsonResponse
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);
        $conversation = $this->requireConversation($idConversation, $invite, $entreprise);

        $this->em->remove($conversation);
        $this->em->flush();

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

        $messageUser = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_USER)
            ->setContenu($contenu);

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
        $reply = $this->aiEngine->reply($this->contextBuilder->build($entreprise, $invite, $conversation));

        $messageAssistant = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu($reply->content)
            ->setMeta(array_filter([
                'engine' => $this->aiEngine->name(),
                'tool'   => $reply->toolUsed,
                'refus'  => $reply->refused ?: null,
            ]));
        $conversation->addMessage($messageAssistant);

        if ($conversation->getTitre() === null) {
            $conversation->setTitre(mb_substr($contenu, 0, 80));
        }

        $this->em->flush();

        return $this->json([
            'user' => [
                'id'        => $messageUser->getId(),
                'contenu'   => $messageUser->getContenu(),
                'createdAt' => $messageUser->getCreatedAt()?->format(\DateTimeImmutable::ATOM),
            ],
            'assistant' => [
                'id'        => $messageAssistant->getId(),
                'contenu'   => $messageAssistant->getContenu(),
                'refus'     => $reply->refused,
                'createdAt' => $messageAssistant->getCreatedAt()?->format(\DateTimeImmutable::ATOM),
            ],
            'conversationTitre' => $conversation->getTitre(),
        ]);
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

    private function currentUser(): Utilisateur
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $user;
    }
}
