<?php

namespace App\Controller\Admin;

use App\Entity\Operation;
use App\Form\OperationType;
use App\Repository\InviteRepository;
use App\Repository\OperationRepository;
use App\Repository\EntrepriseRepository;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Serializer\SerializerInterface;

#[Route("/admin/operation", name: 'admin.operation.')]
#[IsGranted('ROLE_USER')]
class OperationController extends AbstractController
{
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private OperationRepository $operationRepository,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        CanvasBuilder $canvasBuilder
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        // Operations ne contient pas de collections imbriquées pour l'instant.
        return $this->buildCollectionMapFromEntity(Operation::class);
    }

    protected function getParentAssociationMap(): array
    {
        // Operation est liée à Bordereau
        return $this->buildParentAssociationMapFromEntity(Operation::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        return $this->renderViewOrListComponent(Operation::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Operation $operation, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Operation::class,
            OperationType::class,
            $operation,
            function (Operation $operation, \App\Entity\Invite $invite) {
                // Pas d'initialisation spécifique pour Operation pour l'instant,
                // car les champs sont directement liés aux données saisies.
                // Le Bordereau parent sera automatiquement lié via le parentContext.
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            Operation::class,
            OperationType::class,
            function (Operation $operation) {
                // Aucune logique spécifique avant la persistance pour l'instant.
            }
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Operation $operation): Response
    {
        return $this->handleDeleteApi($operation);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(Operation::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Operation::class, $usage);
    }
}