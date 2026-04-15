<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Form\InviteType;
use App\Security\EmailVerifier;
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
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Mime\Address;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
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
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EmailVerifier $emailVerifier,
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

        $response = $this->handleFormSubmission(
            $request,
            Invite::class,
            InviteType::class,
            function (Invite $invite) use ($request) {
                // Ce callback est exécuté avant la persistance de l'entité.
                // C'est l'endroit parfait pour gérer la logique de l'email.
                if (!$invite->getId()) { // Uniquement en mode création
                    $data = $request->request->all();
                    $email = $data['email'] ?? null;

                    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        // Normalement, la validation du formulaire devrait déjà l'empêcher.
                        // C'est une sécurité supplémentaire.
                        throw new \InvalidArgumentException("L'adresse email fournie est invalide ou manquante.");
                    }

                    $utilisateur = $this->utilisateurRepository->findOneBy(['email' => $email]);

                    if (!$utilisateur) {
                        // L'utilisateur n'existe pas, on le crée.
                        $utilisateur = new Utilisateur();
                        $utilisateur->setEmail($email);
                        $utilisateur->setNom($data['nom'] ?? 'Nouveau Collaborateur');
                        // Créer un mot de passe temporaire et sécurisé
                        $randomPassword = bin2hex(random_bytes(16));
                        $utilisateur->setPassword($this->passwordHasher->hashPassword($utilisateur, $randomPassword));
                        $utilisateur->setVerified(false); // L'utilisateur devra vérifier son email
                        $this->em->persist($utilisateur);
                    }

                    // On lie l'utilisateur (existant ou nouveau) à l'invité.
                    $invite->setUtilisateur($utilisateur);
                }
            }
        );

        // Si c'est une création réussie, on déclenche l'événement d'invitation
        $isNew = !isset($request->request->all()['id']) || empty($request->request->all()['id']);
        if ($response->getStatusCode() === 200 && $isNew) {
            $responseData = json_decode($response->getContent(), true);
            if (isset($responseData['entity']['id'])) {
                $newInvite = $this->inviteRepository->find($responseData['entity']['id']);
                if ($newInvite) {
                    try {
                        $this->dispatcher->dispatch(new InvitationEvent($newInvite));

                        // Si l'utilisateur vient d'être créé, on envoie l'email de vérification/invitation
                        $invitedUser = $newInvite->getUtilisateur();
                        if ($invitedUser && !$invitedUser->isVerified()) {
                            $this->emailVerifier->sendEmailConfirmation(
                                'app_verify_email',
                                $invitedUser,
                                (new TemplatedEmail())
                                    ->from(new Address('support@demo.fr', 'Support JS-Brokers'))
                                    ->to((string) $invitedUser->getEmail())
                                    ->subject('Vous êtes invité à rejoindre ' . $newInvite->getEntreprise()->getNom())
                                    ->htmlTemplate('registration/invitation_email.html.twig')
                            );
                        }
                    } catch (\Throwable $th) {
                        $responseData['warning'] = $this->translator->trans("invite_email_sending_error");
                        return new JsonResponse($responseData);
                    }
                }
            }
        }

        return $response;
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Invite $invite): Response
    {
        return $this->handleDeleteApi($invite);
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
