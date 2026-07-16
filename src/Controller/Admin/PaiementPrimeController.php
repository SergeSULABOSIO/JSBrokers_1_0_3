<?php

/**
 * @file Contrôleur CRUD de l'entité `PaiementPrime` (signalement du paiement d'une
 * prime d'assurance encaissée par l'ASSUREUR — trace déclarative par tranche, sans
 * aucun impact sur la trésorerie du courtier). Points de terminaison API du dialogue
 * (get-form/submit/delete) + collection (preuves documentaires) + liste dynamique.
 */

namespace App\Controller\Admin;

use DateTimeImmutable;
use App\Entity\PaiementPrime;
use App\Form\PaiementPrimeType;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Repository\PaiementPrimeRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\Canvas\CalculationProvider;
use App\Services\JSBDynamicSearchService;
use App\Services\CanvasBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/paiementprime", name: 'admin.paiementprime.')]
#[IsGranted('ROLE_USER')]
class PaiementPrimeController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private PaiementPrimeRepository $paiementPrimeRepository,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private CalculationProvider $calculationProvider,
        CanvasBuilder $canvasBuilder
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(PaiementPrime::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(PaiementPrime::class);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?PaiementPrime $paiementPrime, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            PaiementPrime::class,
            PaiementPrimeType::class,
            $paiementPrime,
            function (PaiementPrime $paiementPrime) {
                $paiementPrime->setPaidAt(new DateTimeImmutable('now'));
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            PaiementPrime::class,
            PaiementPrimeType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(PaiementPrime $paiementPrime): Response
    {
        return $this->handleDeleteApi($paiementPrime);
    }

    #[Route(
        '/api/dynamic-query/{idInvite}/{idEntreprise}',
        name: 'app_dynamic_query',
        requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS],
        methods: ['POST']
    )]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(PaiementPrime::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, PaiementPrime::class, $usage);
    }
}
