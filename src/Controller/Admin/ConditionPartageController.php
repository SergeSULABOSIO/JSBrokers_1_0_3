<?php

namespace App\Controller\Admin;

use App\Entity\ConditionPartage;
use App\Form\ConditionPartageType;
use App\Repository\ConditionPartageRepository;
use App\Constantes\Constante;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use App\Services\CanvasBuilder;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/conditionpartage", name: 'admin.conditionpartage.')]
#[IsGranted('ROLE_USER')]
class ConditionPartageController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private InviteRepository $inviteRepository,
        private EntrepriseRepository $entrepriseRepository,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private Constante $constante,
        private ConditionPartageRepository $conditionPartageRepository,
        private CanvasBuilder $canvasBuilder, // Ajout de CanvasBuilder
    ) {
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(ConditionPartage::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(ConditionPartage::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(ConditionPartage::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?ConditionPartage $conditionPartage, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            ConditionPartage::class,
            ConditionPartageType::class,
            $conditionPartage
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            ConditionPartage::class,
            ConditionPartageType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(ConditionPartage $conditionPartage): Response
    {
        return $this->handleDeleteApi($conditionPartage);
    }

    #[Route(
        '/api/dynamic-query/{idInvite}/{idEntreprise}',
        name: 'app_dynamic_query',
        requirements: [
            'idEntreprise' => Requirement::DIGITS,
            'idInvite' => Requirement::DIGITS
        ],
        methods: ['POST']
    )]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(ConditionPartage::class, $request, true);
    }

    #[Route(
        '/api/{id}/{collectionName}/{usage}',
        name: 'api.get_collection',
        requirements: ['id' => Requirement::DIGITS],
        methods: ['GET']
    )]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, ConditionPartage::class, $usage);
    }
}