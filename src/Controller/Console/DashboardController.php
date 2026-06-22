<?php

namespace App\Controller\Console;

use App\Repository\TokenPurchaseRepository;
use App\Services\ConsoleStatsProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Tableau de bord global de la Console JS Brokers : KPIs + graphiques.
 */
#[Route('/console', name: 'console.dashboard')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractConsoleController
{
    public function __construct(
        private ConsoleStatsProvider $stats,
        private TokenPurchaseRepository $purchaseRepository,
    ) {}

    public function __invoke(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->render('console/dashboard.html.twig', [
            'pageName'        => 'Tableau de bord',
            'kpis'            => $this->stats->getKpis(),
            'chartVentesMois' => $this->stats->chartVentesParMois(),
            'chartVentesPack' => $this->stats->chartVentesParPaquet(),
            'dernieresVentes' => $this->purchaseRepository->paginateFiltered([], 1),
        ]);
    }
}
