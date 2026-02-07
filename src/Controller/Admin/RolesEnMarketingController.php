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
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route("/admin/rolesenmarketing", name: 'admin.rolesenmarketing.')]
#[IsGranted('ROLE_USER')]
class RolesEnMarketingController extends AbstractController
{
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private InviteRepository $inviteRepository,
        CanvasBuilder $canvasBuilder
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?RolesEnMarketing $role, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            RolesEnMarketing::class,
            RolesEnMarketingType::class,
            $role,
            function (RolesEnMarketing $role, Invite $invite) {
                $role->setInvite($invite);
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