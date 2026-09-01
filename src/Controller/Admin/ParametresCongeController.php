<?php

namespace App\Controller\Admin;

use App\Constantes\Constante;
use App\Entity\Invite;
use App\Entity\ParametresConge;
use App\Entity\Traits\HandleChildAssociationTrait;
use App\Form\ParametresCongeType;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Repository\ParametresCongeRepository;
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
 * Réglages du module de congés (module Administration → Congés).
 *
 * ── UN SEUL JEU DE RÉGLAGES PAR CABINET ─────────────────────────────────────────────
 * La rubrique n'offre PAS la création : deux jeux concurrents, ce serait un contrôle qui
 * s'applique ou non selon la ligne qu'on a lue. Le formulaire d'ajout est donc refusé
 * avec son motif, et l'unique ligne du cabinet s'édite.
 *
 * Elle partage le droit du paramétrage (RolesEnAdministration::accessCongeParametre) avec
 * les types d'absence et les jours fériés : on confie ces trois réglages ensemble, ou
 * aucun.
 */
#[Route("/admin/parametresconge", name: 'admin.parametresconge.')]
#[IsGranted('ROLE_USER')]
class ParametresCongeController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ParametresCongeRepository $parametresCongeRepository,
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
        return $this->buildCollectionMapFromEntity(ParametresConge::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(ParametresConge::class);
    }

    /**
     * LA LIGNE EXISTE AVANT QU'ON L'OUVRE.
     *
     * Un cabinet qui n'a jamais touché à ses réglages n'a pas de ligne en base : la
     * rubrique s'ouvrirait vide, et personne ne saurait qu'il faut d'abord « créer » des
     * paramètres qui ont pourtant déjà des valeurs par défaut actives. On la matérialise
     * donc au premier affichage — c'est le seul endroit où cette écriture a un sens.
     */
    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        $this->materialiserLesReglages();

        return $this->renderViewOrListComponent(ParametresConge::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?ParametresConge $parametresConge, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            ParametresConge::class,
            ParametresCongeType::class,
            $parametresConge,
            function (ParametresConge $parametres, Invite $invite) {
                $parametres->setEntreprise($invite->getEntreprise());
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission($request, ParametresConge::class, ParametresCongeType::class);
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(ParametresConge $parametresConge): Response
    {
        return $this->handleDeleteApi($parametresConge);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(ParametresConge::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = 'generic'): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, ParametresConge::class, $usage);
    }

    /**
     * Crée la ligne de réglages du cabinet si elle n'existe pas encore.
     *
     * Le repository rend TOUJOURS un objet — persisté ou non. On ne persiste ici que
     * celui qui vient de naître, et une seule fois : au second passage il est trouvé en
     * base et rien ne s'écrit.
     */
    private function materialiserLesReglages(): void
    {
        $entreprise = $this->getEntreprise();
        $parametres = $this->parametresCongeRepository->pourEntreprise($entreprise, $this->getInvite());

        if ($parametres->getId() === null) {
            $this->em->persist($parametres);
            $this->em->flush();
        }
    }
}
