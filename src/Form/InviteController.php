<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Form\InviteType;
use App\Repository\InviteRepository;
use App\Services\CanvasBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route("/admin/invite", name: 'admin.invite.')]
#[IsGranted('ROLE_USER')]
class InviteController extends AbstractController
{
    use ControllerUtilsTrait;
    use \App\Entity\Traits\HandleChildAssociationTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private InviteRepository $inviteRepository,
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

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Invite $invite, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Invite::class,
            InviteType::class,
            $invite,
            function (Invite $inviteEntity, Invite $userInvite) {
                $inviteEntity->setInvite($userInvite);
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            Invite::class,
            InviteType::class
        );
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
