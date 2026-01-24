<?php

namespace App\Controller\Admin;

use App\Entity\Groupe;
use App\Entity\Invite;
use App\Form\GroupeType;
use App\Constantes\Constante;
use App\Repository\GroupeRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
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


#[Route("/admin/groupe", name: 'admin.groupe.')]
#[IsGranted('ROLE_USER')]
class GroupeController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private GroupeRepository $groupeRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer, // Ajout de SerializerInterface
        private CalculationProvider $calculationProvider, // Ajout de CalculationProvider
        CanvasBuilder $canvasBuilder // Inject CanvasBuilder without property promotion
    ) {
        // Assign the injected CanvasBuilder to the property declared in the trait
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Groupe::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Groupe::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(Groupe::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Groupe $groupe, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Groupe::class,
            GroupeType::class,
            $groupe,
            function (Groupe $groupe, Invite $invite) {
                $groupe->setEntreprise($invite->getEntreprise());
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            Groupe::class,
            GroupeType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Groupe $groupe): Response
    {
        return $this->handleDeleteApi($groupe);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(Groupe::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Groupe::class, $usage);
    }
}
