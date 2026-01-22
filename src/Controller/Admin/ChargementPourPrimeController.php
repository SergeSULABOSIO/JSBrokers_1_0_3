<?php

namespace App\Controller\Admin;

use App\Entity\ChargementPourPrime;
use App\Entity\Invite;
use App\Form\ChargementPourPrimeType;
use App\Constantes\Constante;
use App\Repository\ChargementPourPrimeRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use App\Services\CanvasBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/chargementpourprime", name: 'admin.chargementpourprime.')]
#[IsGranted('ROLE_USER')]
class ChargementPourPrimeController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ChargementPourPrimeRepository $chargementPourPrimeRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private CanvasBuilder $canvasBuilder, // Ajout de CanvasBuilder
    ) {}

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(ChargementPourPrime::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(ChargementPourPrime::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(ChargementPourPrime::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?ChargementPourPrime $chargementPourPrime, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            ChargementPourPrime::class,
            ChargementPourPrimeType::class,
            $chargementPourPrime,
            function (ChargementPourPrime $chargementPourPrime, Invite $invite) {
                $chargementPourPrime->setNom("Nouveau chargement");
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            ChargementPourPrime::class,
            ChargementPourPrimeType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(ChargementPourPrime $chargementPourPrime): Response
    {
        return $this->handleDeleteApi($chargementPourPrime);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request)
    {
        return $this->renderViewOrListComponent(ChargementPourPrime::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, ChargementPourPrime::class, $usage);
    }
}