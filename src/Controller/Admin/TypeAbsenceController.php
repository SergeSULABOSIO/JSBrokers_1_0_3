<?php

namespace App\Controller\Admin;

use App\Constantes\Constante;
use App\Entity\Invite;
use App\Entity\TypeAbsence;
use App\Entity\Traits\HandleChildAssociationTrait;
use App\Form\TypeAbsenceType;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Repository\TypeAbsenceRepository;
use App\Services\Canvas\CalculationProvider;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * CRUD workspace des types d'absence du cabinet (module Administration → Congés).
 *
 * Rubrique de PARAMÉTRAGE : gating par RolesEnAdministration::accessCongeParametre,
 * partagé avec les jours fériés. Scoping entreprise, métrage des tokens et contrôle
 * d'accès viennent de ControllerUtilsTrait.
 *
 * Un type déjà utilisé ne se supprime pas — il se désactive. La suppression reste
 * ouverte pour un type créé par erreur et jamais employé ; la base s'en charge, une
 * clé étrangère protégeant les demandes et les mouvements qui le référencent.
 */
#[Route("/admin/typeabsence", name: 'admin.typeabsence.')]
#[IsGranted('ROLE_USER')]
class TypeAbsenceController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private TypeAbsenceRepository $typeAbsenceRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private CalculationProvider $calculationProvider,
        CanvasBuilder $canvasBuilder,
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(TypeAbsence::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(TypeAbsence::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(TypeAbsence::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?TypeAbsence $typeAbsence, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            TypeAbsence::class,
            TypeAbsenceType::class,
            $typeAbsence,
            function (TypeAbsence $type, Invite $invite) {
                $type->setEntreprise($invite->getEntreprise());
                $type->setCode('');
                $type->setLibelle('');
                // Les défauts du type le plus courant : un congé annuel, décompté, sans
                // justificatif. Le cabinet n'a plus qu'à nommer ce qu'il ajoute.
                $type->setDecompte(true);
                $type->setJustificatifRequis(false);
                $type->setAutoriseDemiJournee(true);
                $type->setActif(true);
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission($request, TypeAbsence::class, TypeAbsenceType::class);
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(TypeAbsence $typeAbsence): Response
    {
        return $this->handleDeleteApi($typeAbsence);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(TypeAbsence::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = 'generic'): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, TypeAbsence::class, $usage);
    }
}
