<?php

namespace App\Controller\Admin;

use App\Entity\Risque;
use App\Form\RisqueType;
use App\Entity\Invite;
use App\Constantes\Constante;
use App\Repository\InviteRepository;
use App\Repository\RisqueRepository;
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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/risque", name: 'admin.risque.')]
#[IsGranted('ROLE_USER')]
class RisqueController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private RisqueRepository $risqueRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer, // Ajout de SerializerInterface
        private CanvasBuilder $canvasBuilder, // Ajout de CanvasBuilder
        private CalculationProvider $calculationProvider // Ajout de CalculationProvider
    ) {
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Risque::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Risque::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(Risque::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Risque $risque, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Risque::class,
            RisqueType::class,
            $risque,
            function (Risque $risque, Invite $invite) {
                $risque->setCode("RSK" . (rand(0, 100)));
                $risque->setNomComplet("RISQUE" . (rand(2000, 3000)));
                $risque->setPourcentageCommissionSpecifiqueHT(0.1);
                $risque->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE);
                $risque->setImposable(true);
                $risque->setEntreprise($invite->getEntreprise());
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            Risque::class,
            RisqueType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Risque $risque): Response
    {
        return $this->handleDeleteApi($risque);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(Risque::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Risque::class, $usage);
    }
}
