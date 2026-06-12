<?php

namespace App\Controller\Admin;

use App\Entity\Client;
use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Services\CanvasBuilder;
use App\Services\ServiceMonnaies;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Repository\RisqueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/soa', name: 'admin.soa.')]
#[IsGranted('ROLE_USER')]
class SoaController extends AbstractController
{
    use ControllerUtilsTrait;

    protected function getCollectionMap(): array
    {
        return [];
    }

    protected function getParentAssociationMap(): array
    {
        return [];
    }

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private ServiceMonnaies $serviceMonnaies,
        private RisqueRepository $risqueRepository,
        CanvasBuilder $canvasBuilder,
    ) {
        $this->canvasBuilder = $canvasBuilder;
    }

    #[Route('/client/{id}/workspace', name: 'client_workspace', methods: ['GET'])]
    public function clientWorkspace(Client $client): JsonResponse
    {
        $context = $this->buildSoaContext($client);

        $html = $this->renderView('admin/soa/soa_client_workspace.html.twig', $context);

        return $this->json([
            'html'  => $html,
            'title' => 'SOA — ' . $client->getNom(),
        ]);
    }

    #[Route('/client/{id}/apercu', name: 'client_apercu', methods: ['GET'])]
    public function clientApercu(Client $client): Response
    {
        $context = $this->buildSoaContext($client);

        return $this->render('admin/soa/soa_client_standalone.html.twig', $context);
    }

    private function buildSoaContext(Client $client): array
    {
        $entreprise = $this->getEntreprise();

        $this->canvasBuilder->loadAllCalculatedValues($client);

        foreach ($client->getPartenaires() as $partenaire) {
            $this->canvasBuilder->loadAllCalculatedValues($partenaire);
        }

        $polices          = [];
        $pistesEnCours    = [];
        $cotationsEnCours = [];
        $tranches         = [];
        $taches           = [];
        $tacheIds         = [];

        foreach ($client->getPistes() as $piste) {
            $pisteHasAvenant = false;
            foreach ($piste->getCotations() as $c) {
                if (!$c->getAvenants()->isEmpty()) { $pisteHasAvenant = true; break; }
            }
            if (!$piste->isClosed() && $piste->getAvenantDeBase() === null && !$pisteHasAvenant) {
                $pistesEnCours[] = $piste;
            }

            foreach ($piste->getCotations() as $cotation) {
                $avenants = $cotation->getAvenants();

                if ($avenants->isEmpty() && !$piste->isClosed()) {
                    $this->canvasBuilder->loadAllCalculatedValues($cotation);
                    $cotationsEnCours[] = ['cotation' => $cotation, 'piste' => $piste];
                } else {
                    foreach ($avenants as $avenant) {
                        $this->canvasBuilder->loadAllCalculatedValues($avenant);
                        $polices[] = ['avenant' => $avenant, 'cotation' => $cotation, 'piste' => $piste];
                    }
                    foreach ($cotation->getTranches() as $tranche) {
                        $this->canvasBuilder->loadAllCalculatedValues($tranche);
                        $tranches[] = ['tranche' => $tranche, 'cotation' => $cotation, 'piste' => $piste];
                    }
                }

                foreach ($cotation->getTaches() as $tache) {
                    if (!in_array($tache->getId(), $tacheIds, true)) {
                        $taches[]   = $tache;
                        $tacheIds[] = $tache->getId();
                    }
                }
            }

            foreach ($piste->getTaches() as $tache) {
                if (!in_array($tache->getId(), $tacheIds, true)) {
                    $taches[]   = $tache;
                    $tacheIds[] = $tache->getId();
                }
            }
        }

        $sinistres = [];
        foreach ($client->getNotificationSinistres() as $sinistre) {
            $this->canvasBuilder->loadAllCalculatedValues($sinistre);
            $sinistres[] = $sinistre;

            foreach ($sinistre->getTaches() as $tache) {
                if (!in_array($tache->getId(), $tacheIds, true)) {
                    $taches[]   = $tache;
                    $tacheIds[] = $tache->getId();
                }
            }
        }

        foreach ($taches as $tache) {
            $this->canvasBuilder->loadAllCalculatedValues($tache);
        }

        usort($tranches, static function (array $a, array $b): int {
            $dateA = $a['tranche']->getPayableAt();
            $dateB = $b['tranche']->getPayableAt();
            if ($dateA === null && $dateB === null) return 0;
            if ($dateA === null) return 1;
            if ($dateB === null) return -1;
            return $dateA <=> $dateB;
        });

        return [
            'client'           => $client,
            'entreprise'       => $entreprise,
            'monnaie'          => $this->serviceMonnaies->getCodeMonnaieAffichage(),
            'soaRef'           => 'SOA-' . $client->getId() . '-' . date('Y'),
            'soaDate'          => new \DateTimeImmutable(),
            'apercuUrl'        => $this->generateUrl('admin.soa.client_apercu', ['id' => $client->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            'polices'          => $polices,
            'pistesEnCours'    => $pistesEnCours,
            'cotationsEnCours' => $cotationsEnCours,
            'tranches'         => $tranches,
            'sinistres'        => $sinistres,
            'taches'           => $taches,
            'crossSelling'     => $this->buildCrossSellingOpportunites($client, $entreprise),
        ];
    }

    /**
     * Détermine les opportunités de cross-selling avec le client :
     * - 'nouveaux'   : risques du catalogue de l'entreprise jamais abordés avec le client (aucune piste) ;
     * - 'aRelancer'  : risques abordés par le passé mais sans suite (pistes fermées, polices perdues ou résiliées).
     * Les risques avec une piste ouverte ou une police valide sont exclus (déjà couverts ou en négociation).
     */
    private function buildCrossSellingOpportunites(Client $client, ?Entreprise $entreprise): array
    {
        if ($entreprise === null) {
            return ['nouveaux' => [], 'aRelancer' => []];
        }

        $catalogue = $this->risqueRepository->findBy(['entreprise' => $entreprise], ['nomComplet' => 'ASC']);

        // Statut par risque : 'actif' (couvert ou en négociation) > 'policePerdue' > 'pisteFermee'
        $statuts = [];
        foreach ($client->getPistes() as $piste) {
            $risque = $piste->getRisque();
            if ($risque === null) {
                continue;
            }
            $risqueId = $risque->getId();

            $aUnAvenant      = false;
            $aPoliceValide   = false;
            foreach ($piste->getCotations() as $cotation) {
                foreach ($cotation->getAvenants() as $avenant) {
                    $aUnAvenant = true;
                    if (!in_array($avenant->getRenewalStatus(), [Avenant::RENEWAL_STATUS_LOST, Avenant::RENEWAL_STATUS_CANCELLED], true)) {
                        $aPoliceValide = true;
                        break 2;
                    }
                }
            }

            if (!$piste->isClosed() || $aPoliceValide) {
                $statuts[$risqueId] = 'actif';
            } elseif (($statuts[$risqueId] ?? null) !== 'actif') {
                $statuts[$risqueId] = $aUnAvenant ? 'policePerdue' : 'pisteFermee';
            }
        }

        $nouveaux  = [];
        $aRelancer = [];
        foreach ($catalogue as $risque) {
            $statut = $statuts[$risque->getId()] ?? null;
            if ($statut === null) {
                $nouveaux[] = $risque;
            } elseif ($statut !== 'actif') {
                $aRelancer[] = [
                    'risque' => $risque,
                    'motif'  => $statut === 'policePerdue' ? 'Police perdue ou résiliée' : 'Piste(s) fermée(s) sans souscription',
                ];
            }
        }

        return ['nouveaux' => $nouveaux, 'aRelancer' => $aRelancer];
    }
}
