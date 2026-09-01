<?php

namespace App\Controller\Admin;

use App\Constantes\Constante;
use App\Entity\Invite;
use App\Entity\PeriodeBlocage;
use App\Entity\Traits\HandleChildAssociationTrait;
use App\Form\PeriodeBlocageType;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Repository\PeriodeBlocageRepository;
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
 * Périodes de blocage — SANS RUBRIQUE.
 *
 * Ce contrôleur ne sert pas une entrée de menu : il alimente le widget de collection du
 * dialogue « Paramètres des congés », d'où les périodes se saisissent. Ce sont des
 * réglages, pas des objets métier qu'on va chercher dans un menu.
 *
 * Le droit qui les gouverne est celui du paramétrage des congés, par
 * WorkspaceAccessResolver::GOUVERNANCE_PARENT → « ParametresConge ». Aucun droit
 * supplémentaire n'a été créé pour elles.
 */
#[Route("/admin/periodeblocage", name: 'admin.periodeblocage.')]
#[IsGranted('ROLE_USER')]
class PeriodeBlocageController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private PeriodeBlocageRepository $periodeBlocageRepository,
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
        return $this->buildCollectionMapFromEntity(PeriodeBlocage::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(PeriodeBlocage::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(PeriodeBlocage::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?PeriodeBlocage $periodeBlocage, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            PeriodeBlocage::class,
            PeriodeBlocageType::class,
            $periodeBlocage,
            function (PeriodeBlocage $periode, Invite $invite) {
                $periode->setEntreprise($invite->getEntreprise());
                $periode->setLibelle('');
                $periode->setDateDebut(new \DateTimeImmutable('today'));
                $periode->setDateFin(new \DateTimeImmutable('today'));
                $periode->setActif(true);
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission($request, PeriodeBlocage::class, PeriodeBlocageType::class);
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(PeriodeBlocage $periodeBlocage): Response
    {
        return $this->handleDeleteApi($periodeBlocage);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(PeriodeBlocage::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = 'generic'): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, PeriodeBlocage::class, $usage);
    }
}
