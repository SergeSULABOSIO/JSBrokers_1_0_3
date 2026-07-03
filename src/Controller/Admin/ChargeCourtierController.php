<?php

namespace App\Controller\Admin;

use App\Entity\Charge;
use App\Entity\ChargeCourtier;
use App\Entity\Invite;
use App\Constantes\Constante;
use App\Form\ChargeCourtierType;
use App\Repository\ChargeCourtierRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Services\Canvas\CalculationProvider;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * CRUD workspace du référentiel de charges OHADA du courtier (ChargeCourtier).
 * Gating par rôle (RolesEnFinance::accessCharge) et scoping entreprise fournis
 * par ControllerUtilsTrait + JSBDynamicSearchService ; métrage tokens automatique.
 */
#[Route("/admin/chargecourtier", name: 'admin.chargecourtier.')]
#[IsGranted('ROLE_USER')]
class ChargeCourtierController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ChargeCourtierRepository $chargeCourtierRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private CalculationProvider $calculationProvider,
        CanvasBuilder $canvasBuilder
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(ChargeCourtier::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(ChargeCourtier::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(ChargeCourtier::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?ChargeCourtier $charge, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            ChargeCourtier::class,
            ChargeCourtierType::class,
            $charge,
            function (ChargeCourtier $charge, Invite $invite) {
                $charge->setEntreprise($invite->getEntreprise());
                $charge->setCode("");
                $charge->setLibelle("");
                $charge->setCompteOhada('65');
                $charge->setComportement(Charge::COMPORTEMENT_FIXE);
                $charge->setPeriodicite(Charge::PERIODICITE_MENSUELLE);
                $charge->setActif(true);
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            ChargeCourtier::class,
            ChargeCourtierType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(ChargeCourtier $charge): Response
    {
        return $this->handleDeleteApi($charge);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(ChargeCourtier::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, ChargeCourtier::class, $usage);
    }
}
