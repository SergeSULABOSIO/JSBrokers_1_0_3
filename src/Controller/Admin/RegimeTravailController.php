<?php

namespace App\Controller\Admin;

use App\Constantes\Constante;
use App\Entity\Invite;
use App\Entity\RegimeTravail;
use App\Entity\Traits\HandleChildAssociationTrait;
use App\Form\RegimeTravailType;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Repository\RegimeTravailRepository;
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
 * Régimes de travail d'un collaborateur — SANS RUBRIQUE.
 *
 * Ce contrôleur ne sert pas une entrée de menu : il alimente le widget de collection du
 * dialogue « Invité », d'où les régimes se saisissent. Régler le temps de travail de
 * quelqu'un relève du même cercle que gérer les invités, et c'est exactement le droit
 * qui le gouverne (WorkspaceAccessResolver::GOUVERNANCE_PARENT → « Invite », donc
 * canManageInvites()). Aucun droit supplémentaire n'a été créé pour lui.
 *
 * Les régimes sont HISTORISÉS : on en ajoute un nouveau quand le temps de travail
 * change, on ne modifie pas l'ancien. Une demande posée l'an dernier doit rester lisible
 * avec le régime qui était alors le sien.
 */
#[Route("/admin/regimetravail", name: 'admin.regimetravail.')]
#[IsGranted('ROLE_USER')]
class RegimeTravailController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private RegimeTravailRepository $regimeTravailRepository,
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
        return $this->buildCollectionMapFromEntity(RegimeTravail::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(RegimeTravail::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(RegimeTravail::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?RegimeTravail $regimeTravail, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            RegimeTravail::class,
            RegimeTravailType::class,
            $regimeTravail,
            function (RegimeTravail $regime, Invite $invite) {
                $regime->setEntreprise($invite->getEntreprise());
                // Le temps plein du lundi au vendredi : le cas de loin le plus fréquent,
                // et le même défaut que celui appliqué en l'absence de tout régime.
                $regime->setJoursOuvres(RegimeTravail::JOURS_OUVRES_DEFAUT);
                $regime->setTauxOccupation('1.00');
                $regime->setDateDebut(new \DateTimeImmutable('today'));
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission($request, RegimeTravail::class, RegimeTravailType::class);
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(RegimeTravail $regimeTravail): Response
    {
        return $this->handleDeleteApi($regimeTravail);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(RegimeTravail::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = 'generic'): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, RegimeTravail::class, $usage);
    }
}
