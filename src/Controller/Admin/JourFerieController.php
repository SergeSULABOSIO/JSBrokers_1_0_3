<?php

namespace App\Controller\Admin;

use App\Constantes\Constante;
use App\Entity\Invite;
use App\Entity\JourFerie;
use App\Entity\Traits\HandleChildAssociationTrait;
use App\Form\JourFerieType;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Repository\JourFerieRepository;
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
 * CRUD workspace des jours fériés du cabinet (module Administration → Congés).
 *
 * Rubrique de PARAMÉTRAGE : même droit que les types d'absence
 * (RolesEnAdministration::accessCongeParametre).
 *
 * Aucun catalogue n'est semé à la création du cabinet : les jours fériés dépendent du
 * pays, et les dates mobiles de l'année. Le valideur saisit les siens, et un calendrier
 * vide ne casse rien — le décompte ne retire alors que les week-ends et le régime de
 * travail de l'agent.
 */
#[Route("/admin/jourferie", name: 'admin.jourferie.')]
#[IsGranted('ROLE_USER')]
class JourFerieController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private JourFerieRepository $jourFerieRepository,
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
        return $this->buildCollectionMapFromEntity(JourFerie::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(JourFerie::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(JourFerie::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?JourFerie $jourFerie, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            JourFerie::class,
            JourFerieType::class,
            $jourFerie,
            function (JourFerie $ferie, Invite $invite) {
                $ferie->setEntreprise($invite->getEntreprise());
                $ferie->setLibelle('');
                // setDate() pose aussi l'exercice : on ne laisse pas les deux diverger.
                $ferie->setDate(new \DateTimeImmutable('today'));
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission($request, JourFerie::class, JourFerieType::class);
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(JourFerie $jourFerie): Response
    {
        return $this->handleDeleteApi($jourFerie);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(JourFerie::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = 'generic'): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, JourFerie::class, $usage);
    }
}
