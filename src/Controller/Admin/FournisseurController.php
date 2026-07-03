<?php

namespace App\Controller\Admin;

use App\Entity\Fournisseur;
use App\Entity\Invite;
use App\Constantes\Constante;
use App\Form\FournisseurType;
use App\Repository\FournisseurRepository;
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
 * CRUD workspace des fournisseurs professionnels du cabinet (référentiel achats /
 * services généraux, module Finances). Gating par rôle
 * (RolesEnFinance::accessFournisseur) et scoping entreprise fournis par
 * ControllerUtilsTrait + JSBDynamicSearchService ; métrage tokens automatique
 * (lecture ET écriture débitées au propriétaire de l'entreprise).
 */
#[Route("/admin/fournisseur", name: 'admin.fournisseur.')]
#[IsGranted('ROLE_USER')]
class FournisseurController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private FournisseurRepository $fournisseurRepository,
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
        return $this->buildCollectionMapFromEntity(Fournisseur::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Fournisseur::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(Fournisseur::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Fournisseur $fournisseur, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Fournisseur::class,
            FournisseurType::class,
            $fournisseur,
            function (Fournisseur $fournisseur, Invite $invite) {
                $fournisseur->setEntreprise($invite->getEntreprise());
                $fournisseur->setNom("");
                $fournisseur->setActif(true);
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            Fournisseur::class,
            FournisseurType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Fournisseur $fournisseur): Response
    {
        return $this->handleDeleteApi($fournisseur);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(Fournisseur::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Fournisseur::class, $usage);
    }
}
