<?php

namespace App\Controller\Admin;

use App\Entity\Client;
use App\Entity\Invite;
use App\Entity\Portefeuille;
use App\Form\PortefeuilleType;
use App\Constantes\Constante;
use App\Repository\InviteRepository;
use App\Repository\PortefeuilleRepository;
use App\Repository\EntrepriseRepository;
use App\Services\Canvas\CalculationProvider;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Controller\Admin\ControllerUtilsTrait;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/portefeuille", name: 'admin.portefeuille.')]
#[IsGranted('ROLE_USER')]
class PortefeuilleController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private PortefeuilleRepository $portefeuilleRepository,
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
        return $this->buildCollectionMapFromEntity(Portefeuille::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Portefeuille::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(Portefeuille::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Portefeuille $portefeuille, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Portefeuille::class,
            PortefeuilleType::class,
            $portefeuille,
            function (Portefeuille $portefeuille, Invite $invite) {
                $portefeuille->setEntreprise($invite->getEntreprise());
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            Portefeuille::class,
            PortefeuilleType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Portefeuille $portefeuille): Response
    {
        return $this->handleDeleteApi($portefeuille);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(Portefeuille::class, $request, true);
    }

    /**
     * Détache un client du portefeuille (client.portefeuille = null) SANS supprimer le
     * client : action de « retrait » du widget collection. Détruire le client serait
     * destructeur (il est partagé avec ses pistes, cotations, sinistres…).
     */
    #[Route('/api/{id}/detach-client/{clientId}', name: 'api.detach_client', requirements: ['id' => Requirement::DIGITS, 'clientId' => Requirement::DIGITS], methods: ['DELETE'])]
    public function detachClient(Portefeuille $portefeuille, int $clientId): JsonResponse
    {
        // Mutation d'un portefeuille → exige le droit de Modification (fail-closed).
        if (!$this->mayAccessEntity(Portefeuille::class, Invite::ACCESS_MODIFICATION)) {
            return $this->accessDeniedJson();
        }
        // Scoping : le portefeuille doit appartenir à l'espace de travail courant.
        $entreprise = $this->getEntreprise();
        if ($entreprise === null || $portefeuille->getEntreprise()?->getId() !== $entreprise->getId()) {
            return $this->json(['message' => "Portefeuille introuvable dans cet espace de travail."], Response::HTTP_NOT_FOUND);
        }

        $client = $this->em->getRepository(Client::class)->find($clientId);
        if ($client !== null && $client->getPortefeuille()?->getId() === $portefeuille->getId()) {
            $client->setPortefeuille(null); // détache, ne supprime pas
            $this->em->flush();
        }

        return $this->json(['message' => "Client détaché du portefeuille."]);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Portefeuille::class, $usage);
    }
}
