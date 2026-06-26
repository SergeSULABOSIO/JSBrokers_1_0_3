<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Entity\Entreprise;
use App\Repository\EntrepriseRepository;
use App\Repository\TokenConsumptionRepository;
use App\Services\ServiceGeographie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Vue Entreprise (workspace) du CRM : lecture enrichie d'un espace de travail —
 * invités, consommation, activités. Toutes les données sont déjà connues du SaaS.
 */
#[Route('/console/crm/entreprises', name: 'console.crm.entreprise.')]
#[IsGranted('ROLE_ADMIN')]
class CrmEntrepriseController extends AbstractConsoleController
{
    public function __construct(
        private EntrepriseRepository $entrepriseRepository,
        private TokenConsumptionRepository $consumptionRepository,
        private ServiceGeographie $geographie,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $entreprises = $this->entrepriseRepository->paginateAll($request->query->getInt('page', 1));
        $ids = array_map(static fn (Entreprise $e) => $e->getId(), $entreprises->getItems());

        return $this->render('console/crm/entreprise/index.html.twig', [
            'pageName'      => 'CRM — Entreprises',
            'pageIcon'      => 'entreprise',
            'entreprises'   => $entreprises,
            'consommations' => $this->consumptionRepository->totauxParEntreprises($ids),
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => Requirement::DIGITS])]
    public function show(Entreprise $entreprise, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $eid = (int) $entreprise->getId();
        $totaux = $this->consumptionRepository->totauxParEntreprises([$eid]);

        return $this->render('console/crm/entreprise/show.html.twig', [
            'pageName'     => $entreprise->getNom(),
            'pageIcon'     => 'entreprise',
            'entreprise'   => $entreprise,
            'pays'         => $entreprise->getPays() ? $this->geographie->getNomPays($entreprise->getPays()) : null,
            'ville'        => $entreprise->getVille() ? $this->geographie->getNomVille($entreprise->getVille()) : null,
            'consommation' => $totaux[$eid] ?? 0,
            'activites'    => $this->consumptionRepository->recentForEntreprise($eid, 20),
            'breakdown'    => $this->consumptionRepository->breakdownByEntiteForEntreprise($eid),
        ]);
    }
}
