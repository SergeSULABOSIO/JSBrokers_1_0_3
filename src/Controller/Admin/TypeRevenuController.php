<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Entity\TypeRevenu;
use App\Form\TypeRevenuType;
use App\Constantes\Constante;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\TypeRevenuRepository;
use App\Services\Canvas\CalculationProvider;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Controller\Admin\ControllerUtilsTrait;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/typerevenu", name: 'admin.typerevenu.')]
#[IsGranted('ROLE_USER')]
class TypeRevenuController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private TypeRevenuRepository $typerevenuRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private CanvasBuilder $canvasBuilder,
        private CalculationProvider $calculationProvider
    ) {
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(TypeRevenu::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(TypeRevenu::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(TypeRevenu::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?TypeRevenu $typeRevenu, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            TypeRevenu::class,
            TypeRevenuType::class,
            $typeRevenu,
            function (TypeRevenu $typeRevenu, Invite $invite) {
                $typeRevenu->setNom("REVENUE" . (rand(2000, 3000)));
                $typeRevenu->setEntreprise($invite->getEntreprise());
                $typeRevenu->setPourcentage(0.1);
                $typeRevenu->setAppliquerPourcentageDuRisque(true);
                $typeRevenu->setMontantflat(0);
                $typeRevenu->setMultipayments(true);
                $typeRevenu->setRedevable(TypeRevenu::REDEVABLE_ASSUREUR);
                $typeRevenu->setShared(false);
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            TypeRevenu::class,
            TypeRevenuType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(TypeRevenu $typeRevenu): Response
    {
        return $this->handleDeleteApi($typeRevenu);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(TypeRevenu::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, TypeRevenu::class, $usage);
    }
}
