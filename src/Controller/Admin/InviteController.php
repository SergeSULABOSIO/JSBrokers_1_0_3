<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Form\InviteType;
use App\Entity\Utilisateur;
use App\Event\InvitationEvent;
use App\Repository\InviteRepository;
use App\Entity\Traits\HandleChildAssociationTrait;
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
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private EventDispatcherInterface $dispatcher,
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

        $data = $request->request->all();
        $isNew = !isset($data['id']) || empty($data['id']);

        $response = $this->handleFormSubmission(
            $request,
            Invite::class,
            InviteType::class
        );

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
