<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Constantes\Constante;
use App\Entity\RevenuPourCourtier;
use App\Form\RevenuPourCourtierType;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Services\Canvas\CalculationProvider;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use App\Repository\RevenuPourCourtierRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/revenucourtier", name: 'admin.revenucourtier.')]
#[IsGranted('ROLE_USER')]
class RevenuCourtierController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private RevenuPourCourtierRepository $revenuCourtierRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer, // Ajout de SerializerInterface
        private CanvasBuilder $canvasBuilder, // Ajout de CanvasBuilder
        private CalculationProvider $calculationProvider // Ajout de CalculationProvider
    ) {
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(RevenuPourCourtier::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(RevenuPourCourtier::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(RevenuPourCourtier::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?RevenuPourCourtier $revenuPourCourtier, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            RevenuPourCourtier::class,
            RevenuPourCourtierType::class,
            $revenuPourCourtier,
            function (RevenuPourCourtier $revenuPourCourtier, Invite $invite) {
                // Initializer logic if needed
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            RevenuPourCourtier::class,
            RevenuPourCourtierType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(RevenuPourCourtier $revenuPourCourtier): Response
    {
        return $this->handleDeleteApi($revenuPourCourtier);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(RevenuPourCourtier::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, RevenuPourCourtier::class, $usage);
    }
}
