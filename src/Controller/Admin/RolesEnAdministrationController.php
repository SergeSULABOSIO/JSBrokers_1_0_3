<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Services\CanvasBuilder;
use App\Repository\InviteRepository;
use App\Entity\RolesEnAdministration;
use App\Form\RolesEnAdministrationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/rolesenadministration", name: 'admin.rolesenadministration.')]
#[IsGranted('ROLE_USER')]
class RolesEnAdministrationController extends AbstractController
{
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private InviteRepository $inviteRepository,
        CanvasBuilder $canvasBuilder
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
    public function getFormApi(?RolesEnAdministration $role, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            RolesEnAdministration::class,
            RolesEnAdministrationType::class,
            $role,
            function (RolesEnAdministration $role, Invite $invite) {
                $role->setInvite($invite);
                $role->setNom("Droits d'accès dans le module Administration");
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            RolesEnAdministration::class,
            RolesEnAdministrationType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(RolesEnAdministration $role): Response
    {
        return $this->handleDeleteApi($role);
    }
}