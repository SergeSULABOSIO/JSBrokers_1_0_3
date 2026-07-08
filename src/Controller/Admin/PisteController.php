<?php

namespace App\Controller\Admin;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Piste;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Constantes\Constante;
use App\Form\PisteType;
use App\Repository\PisteRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Services\CanvasBuilder;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\ReconductionPartageService;
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

#[Route("/admin/piste", name: 'admin.piste.')]
#[IsGranted('ROLE_USER')]
class PisteController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private PisteRepository $pisteRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private IndicatorCalculationHelper $indicatorHelper,
        private ReconductionPartageService $reconductionPartage,
        CanvasBuilder $canvasBuilder
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Piste::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Piste::class);
    }

    #[Route('/test', name: 'test')]
    public function edit(): Response
    {
        return $this->render('components/piste/editor.html.twig', []);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        return $this->renderViewOrListComponent(Piste::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Piste $piste, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Piste::class,
            PisteType::class,
            $piste,
            function (Piste $piste, Invite $invite) use ($request) {
                $piste->setRenewalCondition(Piste::RENEWAL_CONDITION_RENEWABLE);

                // Pré-remplissage cross-selling (menu contextuel du SOA) : ?idClient=&idRisque=
                $idClient = (int) $request->query->get('idClient', 0);
                $idRisque = (int) $request->query->get('idRisque', 0);
                if ($idClient || $idRisque) {
                    $entreprise = $invite->getEntreprise();

                    $client = $idClient ? $this->em->find(Client::class, $idClient) : null;
                    if ($client && $client->getEntreprise() === $entreprise) {
                        $piste->setClient($client);
                    }

                    $risque = $idRisque ? $this->em->find(Risque::class, $idRisque) : null;
                    if ($risque && $risque->getEntreprise() === $entreprise) {
                        $piste->setRisque($risque);
                        if ($risque->getDescription() !== null) {
                            $piste->setDescriptionDuRisque($risque->getDescription());
                        }
                    }

                    $piste->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION);
                    $piste->setExercice((int) date('Y'));
                    if ($piste->getRisque() && $piste->getClient()) {
                        $piste->setNom(substr($piste->getRisque()->getNomComplet() . ' — ' . $piste->getClient()->getNom(), 0, 255));
                    }
                    return;
                }

                $idAvenant = (int) $request->query->get('idAvenant', 0);
                if (!$idAvenant) return;

                $avenant = $this->em->find(Avenant::class, $idAvenant);
                if (!$avenant) return;

                $src = $avenant->getCotation()?->getPiste();
                if ($src) {
                    $piste->setClient($src->getClient());
                    $piste->setRisque($src->getRisque());
                    if ($src->getDescriptionDuRisque() !== null) {
                        $piste->setDescriptionDuRisque($src->getDescriptionDuRisque());
                    }
                    $cotation = $avenant->getCotation();
                    $piste->setPrimePotentielle($this->indicatorHelper->getCotationMontantPrimePayableParClient($cotation) ?: $src->getPrimePotentielle());
                    $piste->setCommissionPotentielle($this->indicatorHelper->getCotationMontantCommissionTtc($cotation, -1, false) ?: $src->getCommissionPotentielle());
                    $piste->setRenewalCondition($src->getRenewalCondition() ?? Piste::RENEWAL_CONDITION_RENEWABLE);
                    foreach ($src->getPartenaires() as $partenaire) {
                        $piste->addPartenaire($partenaire);
                    }
                    $piste->setNom(substr('Renouvellement — ' . $src->getNom(), 0, 255));
                }

                $piste->setTypeAvenant(Piste::AVENANT_RENOUVELLEMENT);
                $piste->setExercice((int) date('Y'));
            }
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        // On récupère l'invité connecté de manière sécurisée côté serveur
        $inviteConnecte = $this->getInvite();

        return $this->handleFormSubmission(
            $request,
            Piste::class,
            PisteType::class,
            function (Piste $piste) use ($inviteConnecte, $request) {
                if (!$piste->getId()) {
                    $piste->setInvite($inviteConnecte);

                    $idAvenant = (int) $request->request->get('idAvenant', 0);
                    if ($idAvenant) {
                        $avenant = $this->em->find(Avenant::class, $idAvenant);
                        if ($avenant && $avenant->getEntreprise() === $piste->getEntreprise()) {
                            // On persiste la piste AVANT de la référencer depuis l'avenant
                            // managé : Avenant::pisteDeRenouvellement n'a pas de cascade
                            // persist, et un flush anticipé (métrage de tokens) rejetterait
                            // sinon une piste « nouvelle » atteinte via cette relation.
                            $this->em->persist($piste);
                            $avenant->setPisteDeRenouvellement($piste);
                            $piste->setAvenantDeBase($avenant);

                            // Reconduction du partage partenaire (partenaires + conditions
                            // exceptionnelles) depuis la piste de l'avenant de base. Fait au
                            // submit car la collection de conditions n'est ni mappée ni rendue
                            // sur le formulaire de renouvellement.
                            $src = $avenant->getCotation()?->getPiste();
                            if ($src) {
                                $this->reconductionPartage->reconduire(
                                    $src,
                                    $piste,
                                    $piste->getEntreprise(),
                                    $inviteConnecte
                                );
                            }
                        }
                    }
                }
            }
        );
    }

    #[Route('/api/close/{id}', name: 'api.close', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function closeApi(Piste $piste, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('db-piste-close', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Token invalide'], 403);
        }
        $piste->setClosed(true);
        $this->em->flush();
        return $this->json(['success' => true]);
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Piste $piste): Response
    {
        return $this->handleDeleteApi($piste);
    }

    #[Route('/api/set-not-renewable', name: 'api.set_not_renewable', methods: ['POST'])]
    public function setNotRenewableApi(Request $request): JsonResponse
    {
        $avenantId = (int) $request->request->get('avenantId', 0);
        if (!$avenantId) {
            return $this->json(['success' => false, 'message' => 'avenantId manquant'], 400);
        }

        $avenant = $this->em->find(Avenant::class, $avenantId);
        if (!$avenant) {
            return $this->json(['success' => false, 'message' => 'Avenant introuvable'], 404);
        }

        $piste = $avenant->getCotation()?->getPiste();
        if (!$piste) {
            return $this->json(['success' => false, 'message' => 'Piste introuvable'], 404);
        }

        $piste->setRenewalCondition(Piste::RENEWAL_CONDITION_ONCE_OFF_AND_EXTENDABLE);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(Piste::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Piste::class, $usage);
    }
}