<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use App\Entity\PaiementPrime;
use App\Entity\Tranche;
use App\Form\TrancheType;
use App\Constantes\Constante;
use App\Repository\InviteRepository;
use App\Repository\TrancheRepository;
use App\Repository\EntrepriseRepository;
use App\Services\Canvas\CalculationProvider;
use App\Services\CanvasBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/tranche", name: 'admin.tranche.')]
#[IsGranted('ROLE_USER')]
class TrancheController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private TrancheRepository $trancheRepository,
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
        return $this->buildCollectionMapFromEntity(Tranche::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Tranche::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(Tranche::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Tranche $tranche, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Tranche::class,
            TrancheType::class,
            $tranche,
            function (Tranche $tranche, Invite $invite) {
                $tranche
                    ->setNom("Tranche n°" . random_int(1986, 2070))
                    ->setPourcentage(1)
                    ->setPayableAt(new DateTimeImmutable("now"))
                    ->setEcheanceAt(new DateTimeImmutable("+364 days"));
            }
        );
    }

    /**
     * Contexte de l'action « Signaler un paiement de prime » (menu contextuel de la
     * liste, barre d'outils, volet du dialogue) : renvoie le canevas de formulaire
     * PaiementPrime ; le Cerveau ouvre le dialogue de création rattaché à la tranche
     * (parentContext {id, fieldName: 'tranche'}). Le signalement est déclaratif —
     * l'assureur a encaissé la prime, la trésorerie du courtier n'est pas concernée.
     */
    #[Route('/api/get-paiement-prime-context/{id}', name: 'api.get_paiement_prime_context', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getPaiementPrimeContext(Tranche $tranche, Request $request): JsonResponse
    {
        // Mutation à venir (création d'un signalement) : Écriture sur Tranche (fail-closed) —
        // PaiementPrime est une sous-entité structurelle gouvernée par sa tranche.
        if (!$this->mayAccessEntity(Tranche::class, Invite::ACCESS_ECRITURE)) {
            return $this->accessDeniedJson();
        }
        // Scoping : la tranche doit appartenir à l'espace de travail courant.
        if ($tranche->getEntreprise()?->getId() !== $this->getEntreprise()->getId()) {
            return $this->json(['message' => 'Tranche introuvable dans cet espace de travail.'], Response::HTTP_NOT_FOUND);
        }

        $idEntreprise = (int) $request->query->get('idEntreprise', 0);

        return $this->json([
            'trancheId'  => $tranche->getId(),
            'trancheNom' => $tranche->getNom(),
            'formCanvas' => $this->canvasBuilder->getEntityFormCanvas(new PaiementPrime(), $idEntreprise),
        ]);
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            Tranche::class,
            TrancheType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Tranche $tranche): Response
    {
        return $this->handleDeleteApi($tranche);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(Tranche::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Tranche::class, $usage);
    }
}
