<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Form\InviteType;
use App\Entity\Utilisateur;
use App\Constantes\Constante;
use App\Event\InvitationEvent;
use App\Repository\InviteRepository;
use App\Entity\Traits\HandleChildAssociationTrait;
use App\Repository\UtilisateurRepository;
use App\Repository\EntrepriseRepository;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/invite", name: 'admin.invite.')]
#[IsGranted('ROLE_USER')]
class InviteController extends AbstractController
{
    use ControllerUtilsTrait;
    use HandleChildAssociationTrait;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $em,
        private readonly EntrepriseRepository $entrepriseRepository,
        private readonly InviteRepository $inviteRepository,
        private readonly UtilisateurRepository $utilisateurRepository,
        private readonly Constante $constante,
        private readonly JSBDynamicSearchService $searchService,
        private readonly SerializerInterface $serializer,
        private readonly EventDispatcherInterface $dispatcher,
        CanvasBuilder $canvasBuilder
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Invite::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Invite::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        return $this->renderViewOrListComponent(Invite::class, $request);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(Invite::class, $request, true);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Invite $invite, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Invite::class,
            InviteType::class,
            $invite,
            function (Invite $invite, \App\Entity\Invite $userInvite) {
                $invite->setEntreprise($userInvite->getEntreprise());
                $invite->setProprietaire(false);
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        if ($this->entrepriseRepository->getNBMyProperEntreprises() == 0) {
            return $this->json(['message' => $this->translator->trans("invite_sending_invite_not_granted", [':user' => $user->getNom()])], 403);
        }

        // On valide l'email et l'absence de doublon EN AMONT, pour renvoyer une erreur
        // 422 propre (affichée par le canevas) plutôt qu'une 500.
        // - En création : toujours.
        // - En édition : uniquement pour une invitation encore EN ATTENTE. L'email d'une
        //   invitation déjà rattachée à un compte appartient au compte utilisateur et
        //   n'est pas modifiable ici (le champ est rendu en lecture seule).
        $data = $request->request->all();
        $isCreation = empty($data['id']);
        $editedInvite = $isCreation ? null : $this->inviteRepository->find($data['id']);
        $handleEmail = $isCreation || ($editedInvite && $editedInvite->isEnAttente());
        if ($handleEmail) {
            $email = $data['email'] ?? null;
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json(['message' => "Veuillez corriger les erreurs ci-dessous.", 'errors' => ['email' => ["L'adresse email fournie est invalide ou manquante."]]], 422);
            }
            // En édition, on exclut l'invitation en cours pour ne pas la détecter elle-même comme doublon.
            if ($this->isEmailAlreadyInvited($this->getEntreprise(), $email, $editedInvite)) {
                return $this->json(['message' => "Veuillez corriger les erreurs ci-dessous.", 'errors' => ['email' => ["Cette personne est déjà invitée dans cette entreprise."]]], 422);
            }
        }

        $response = $this->handleFormSubmission(
            $request,
            Invite::class,
            InviteType::class,
            function (Invite $invite) use ($request) {
                // Ce callback est exécuté après validation du formulaire, avant la persistance.
                // On y résout l'état de l'invitation à partir de l'email saisi, en création
                // comme en édition d'une invitation EN ATTENTE. On NE touche PAS à l'email
                // d'une invitation déjà rattachée à un compte : il appartient au compte
                // utilisateur et se modifie ailleurs (champ rendu en lecture seule).
                if ($invite->getId() && !$invite->isEnAttente()) {
                    return;
                }

                $email = $request->request->all()['email'] ?? null;

                // Inviter ≠ créer un compte : on NE pré-crée PAS d'Utilisateur.
                // - Si un compte existe déjà avec cet email → invitation ACTIVE (rattachée).
                // - Sinon → invitation EN ATTENTE (on conserve l'email, utilisateur = null) ;
                //   elle sera rattachée automatiquement quand la personne créera son compte.
                $utilisateur = $this->utilisateurRepository->findOneBy(['email' => $email]);
                if ($utilisateur) {
                    $invite->setUtilisateur($utilisateur);
                    $invite->setEmail(null);
                } else {
                    $invite->setUtilisateur(null);
                    $invite->setEmail($email);
                }
            }
        );

        // Si c'est une création réussie, on déclenche l'événement d'invitation.
        // L'envoi de l'email (variante « créer un compte » ou « se connecter ») est
        // entièrement délégué au MailingSubscriber, source unique de l'envoi.
        $isNew = !isset($request->request->all()['id']) || empty($request->request->all()['id']);
        if ($response->getStatusCode() === 200 && $isNew) {
            $responseData = json_decode($response->getContent(), true);
            if (isset($responseData['entity']['id'])) {
                $newInvite = $this->inviteRepository->find($responseData['entity']['id']);
                if ($newInvite) {
                    try {
                        $this->dispatcher->dispatch(new InvitationEvent($newInvite));
                    } catch (\Throwable $th) {
                        $responseData['warning'] = $this->translator->trans("invite_email_sending_error");
                        return new JsonResponse($responseData);
                    }
                }
            }
        }

        return $response;
    }

    /**
     * Vrai si l'adresse email est déjà rattachée à une invitation de cette entreprise,
     * qu'elle soit en attente (email stocké) ou active (compte rattaché). S'appuie sur
     * Invite::getEmail() qui couvre les deux états.
     */
    private function isEmailAlreadyInvited(\App\Entity\Entreprise $entreprise, string $email, ?Invite $exclude = null): bool
    {
        foreach ($entreprise->getInvites() as $invite) {
            if ($exclude !== null && $invite === $exclude) {
                continue; // En édition : ne pas se détecter soi-même comme doublon.
            }
            if (strcasecmp((string) $invite->getEmail(), $email) === 0) {
                return true;
            }
        }
        return false;
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Invite $invite): Response
    {
        return $this->handleDeleteApi($invite);
    }

    #[Route('/api/resend-invitation/{id}', name: 'api.resend_invitation', methods: ['POST'])]
    public function resendInvitation(Invite $invite): JsonResponse
    {
        try {
            $this->dispatcher->dispatch(new InvitationEvent($invite));
            return $this->json(['success' => true]);
        } catch (\Throwable) {
            return $this->json(['success' => false, 'message' => $this->translator->trans('invite_email_sending_error')], 500);
        }
    }

    #[Route('/api/{id}/{collectionName}/{usage?}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], defaults: ['usage' => 'generic'], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, string $usage): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Invite::class, $usage);
    }

    #[Route('/api/get-entity-details/{entityType}/{id}', name: 'api.get_entity_details', methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]
    public function getEntityDetailsApi(string $entityType, int $id): JsonResponse
    {
        $details = $this->getEntityDetailsForType($entityType, $id);
        return $this->json($details, 200, [], ['groups' => 'list:read']);
    }
}
