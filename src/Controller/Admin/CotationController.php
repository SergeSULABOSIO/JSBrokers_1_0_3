<?php

namespace App\Controller\Admin;

use App\Constantes\Constante;
use App\Entity\Cotation;
use App\Entity\Tranche;
use App\Form\CotationType;
use App\Repository\CotationRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
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

#[Route("/admin/cotation", name: 'admin.cotation.')]
#[IsGranted('ROLE_USER')]
class CotationController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private CotationRepository $cotationRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        CanvasBuilder $canvasBuilder // Inject CanvasBuilder without property promotion
    ) {
        // Assign the injected CanvasBuilder to the property declared in the trait
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Cotation::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Cotation::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(Cotation::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Cotation $cotation, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Cotation::class,
            CotationType::class,
            $cotation
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            Cotation::class,
            CotationType::class,
            function (Cotation $cotation) {
                if ($cotation->getId() === null && $cotation->getTranches()->isEmpty()) {
                    $dateBase = new \DateTimeImmutable('today');
                    $duree = $cotation->getDuree();
                    $echeanceAt = $duree > 0 ? ($dateBase->modify('+' . $duree . ' months') ?: null) : null;
                    $cotation->addTranche(
                        (new Tranche())
                            ->setNom('Tranche unique')
                            ->setPourcentage(1.0)
                            ->setPayableAt($dateBase)
                            ->setEcheanceAt($echeanceAt)
                            ->setEntreprise($cotation->getEntreprise())
                            ->setInvite($cotation->getInvite())
                    );
                }
            }
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Cotation $cotation): Response
    {
        return $this->handleDeleteApi($cotation);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request)
    {
        return $this->renderViewOrListComponent(Cotation::class, $request, true);
    }

    #[Route('/api/{id}/tranches/{usage}', name: 'api.get_cotation_tranches', methods: ['GET'])]
    public function getCotationTranchesApi(int $id, string $usage = 'generic'): Response
    {
        $defaultCreated = false;
        $tranche = null;

        if ($id > 0) {
            $cotation = $this->em->find(Cotation::class, $id);
            if ($cotation && $cotation->getTranches()->isEmpty()) {
                $dateEffet = new \DateTimeImmutable('today');
                if (!$cotation->getAvenants()->isEmpty()) {
                    $firstAvenant = $cotation->getAvenants()->first();
                    if ($firstAvenant->getStartingAt()) {
                        $dateEffet = $firstAvenant->getStartingAt();
                    }
                }
                $duree = $cotation->getDuree();
                $echeanceAt = ($duree > 0) ? ($dateEffet->modify('+' . $duree . ' months') ?: null) : null;

                $tranche = (new Tranche())
                    ->setNom('Tranche unique')
                    ->setPourcentage(1.0)
                    ->setPayableAt($dateEffet)
                    ->setEcheanceAt($echeanceAt)
                    ->setEntreprise($cotation->getEntreprise())
                    ->setInvite($cotation->getInvite());
                $cotation->addTranche($tranche);
                $this->em->persist($tranche);
                $this->em->flush();
                $defaultCreated = true;
            }
        }

        $response = $this->handleCollectionApiRequest($id, 'tranches', Cotation::class, $usage);

        if ($defaultCreated && $response->getStatusCode() === 200) {
            $data = json_decode($response->getContent(), true);
            $data['defaultCreated'] = true;
            $data['defaultItemId'] = $tranche->getId();
            $response->setContent(json_encode($data));
        }

        return $response;
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Cotation::class, $usage);
    }
}
