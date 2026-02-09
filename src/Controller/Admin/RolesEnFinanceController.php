<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Entity\RolesEnFinance;
use App\Services\CanvasBuilder;
use App\Form\RolesEnFinanceType;
use App\Repository\InviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/rolesenfinance", name: 'admin.rolesenfinance.')]
#[IsGranted('ROLE_USER')]
class RolesEnFinanceController extends AbstractController
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
    public function getFormApi(?RolesEnFinance $role, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            RolesEnFinance::class,
            RolesEnFinanceType::class,
            $role,
            function (RolesEnFinance $role, Invite $invite) {
                $role->setInvite($invite);
                $role->setNom("Droits d'accès dans le module Finance");
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            RolesEnFinance::class,
            RolesEnFinanceType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(RolesEnFinance $role): Response
    {
        return $this->handleDeleteApi($role);
    }
}