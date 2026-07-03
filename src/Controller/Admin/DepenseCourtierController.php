<?php

namespace App\Controller\Admin;

use App\Entity\Depense;
use App\Entity\DepenseCourtier;
use App\Entity\Invite;
use App\Constantes\Constante;
use App\Form\DepenseCourtierType;
use App\Repository\DepenseCourtierRepository;
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
 * CRUD workspace des dépenses réelles du courtier (DepenseCourtier), classées par
 * ChargeCourtier. Gating par rôle (RolesEnFinance::accessDepense) et scoping
 * entreprise fournis par ControllerUtilsTrait + JSBDynamicSearchService ; métrage
 * tokens automatique. Ces dépenses alimentent les documents comptables du courtier.
 */
#[Route("/admin/depensecourtier", name: 'admin.depensecourtier.')]
#[IsGranted('ROLE_USER')]
class DepenseCourtierController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private DepenseCourtierRepository $depenseCourtierRepository,
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
        return $this->buildCollectionMapFromEntity(DepenseCourtier::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(DepenseCourtier::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(DepenseCourtier::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?DepenseCourtier $depense, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            DepenseCourtier::class,
            DepenseCourtierType::class,
            $depense,
            function (DepenseCourtier $depense, Invite $invite) {
                $depense->setEntreprise($invite->getEntreprise());
                $depense->setDateDepense(new \DateTimeImmutable('today'));
                $depense->setTauxTva('0.00');
                $depense->setMoyenPaiement(Depense::MOYEN_BANQUE);
                $depense->setStatut(Depense::STATUT_ENGAGEE);
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            DepenseCourtier::class,
            DepenseCourtierType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(DepenseCourtier $depense): Response
    {
        return $this->handleDeleteApi($depense);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(DepenseCourtier::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, DepenseCourtier::class, $usage);
    }
}
