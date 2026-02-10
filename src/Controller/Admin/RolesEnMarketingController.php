<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Entity\RolesEnMarketing;
use App\Form\RolesEnMarketingType;
use App\Repository\InviteRepository;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route("/admin/rolesenmarketing", name: 'admin.rolesenmarketing.')]
#[IsGranted('ROLE_USER')]
class RolesEnMarketingController extends AbstractController
{
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private InviteRepository $inviteRepository,
        CanvasBuilder $canvasBuilder,
        private SerializerInterface $serializer
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return [];
    }

    protected function getParentAssociationMap(): array
    {
        return [];
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?RolesEnMarketing $role, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            RolesEnMarketing::class,
            RolesEnMarketingType::class,
            $role,
            function (RolesEnMarketing $role, Invite $connectedInvite) use ($request) {
                // Prioritize the invite ID from the request query parameters if available
                $targetInviteId = $request->query->get('idInvite');
                $targetInvite = null;

                if ($targetInviteId) {
                    $targetInvite = $this->inviteRepository->find($targetInviteId);
                }

                // Set the invite for the new role, prioritizing the target invite if found
                // Otherwise, default to the connected user's invite
                $role->setInvite($targetInvite ?? $connectedInvite);
                $role->setNom("Droits d'accès dans le module Marketing");
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            RolesEnMarketing::class,
            RolesEnMarketingType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(RolesEnMarketing $role): Response
    {
        return $this->handleDeleteApi($role);
    }
}