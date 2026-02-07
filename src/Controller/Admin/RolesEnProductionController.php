<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Entity\RolesEnProduction;
use App\Form\RolesEnProductionType;
use App\Repository\InviteRepository;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route("/admin/rolesenproduction", name: 'admin.rolesenproduction.')]
#[IsGranted('ROLE_USER')]
class RolesEnProductionController extends AbstractController
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
    public function getFormApi(?RolesEnProduction $role, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            RolesEnProduction::class,
            RolesEnProductionType::class,
            $role,
            function (RolesEnProduction $role, Invite $invite) {
                $role->setInvite($invite);
                $role->setNom("Droits d'accès dans le module Production");
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            RolesEnProduction::class,
            RolesEnProductionType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(RolesEnProduction $role): Response
    {
        return $this->handleDeleteApi($role);
    }
}