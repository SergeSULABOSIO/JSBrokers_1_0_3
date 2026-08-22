<?php

/**
 * @file Contrôleur CRUD de l'entité `ReversementRetroAgent` — la part d'une commission
 * encaissée, reversée à l'agent interne qui a apporté l'affaire.
 *
 * ── UNE RUBRIQUE DE CONSULTATION ────────────────────────────────────────────────────
 * La CRÉATION n'a pas sa place ici, et le canevas la refuse (`creation_interdite`) : un
 * reversement ne s'enregistre pas sans justificatif, or les pièces d'une fiche s'attachent
 * APRÈS sa création. Ses deux portes restent le picker du rapport de production et
 * l'assistant, tous deux transactionnels — la pièce y part du même geste que le montant.
 *
 * Cette rubrique sert à RELIRE, à corriger une référence, et surtout à joindre un
 * justificatif oublié : c'est elle qui fait apparaître les actions « Attacher des pièces »
 * et « Voir les documents », injectées par FormCanvasProvider sur toute entité portant une
 * collection de documents. Sans écran, elles n'avaient nulle part où s'afficher.
 */

namespace App\Controller\Admin;

use App\Entity\ReversementRetroAgent;
use App\Entity\Traits\HandleChildAssociationTrait;
use App\Form\ReversementRetroAgentType;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Services\Canvas\CalculationProvider;
use App\Services\CanvasBuilder;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

#[Route("/admin/reversementretroagent", name: 'admin.reversementretroagent.')]
#[IsGranted('ROLE_USER')]
class ReversementRetroAgentController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private CalculationProvider $calculationProvider,
        CanvasBuilder $canvasBuilder
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(ReversementRetroAgent::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(ReversementRetroAgent::class);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?ReversementRetroAgent $reversement, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            ReversementRetroAgent::class,
            ReversementRetroAgentType::class,
            $reversement,
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            ReversementRetroAgent::class,
            ReversementRetroAgentType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(ReversementRetroAgent $reversement): Response
    {
        return $this->handleDeleteApi($reversement);
    }

    #[Route(
        '/api/dynamic-query/{idInvite}/{idEntreprise}',
        name: 'app_dynamic_query',
        requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS],
        methods: ['POST']
    )]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(ReversementRetroAgent::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, ReversementRetroAgent::class, $usage);
    }
}
