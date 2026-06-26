<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Repository\Crm\CrmProfilRepository;
use App\Repository\TokenPurchaseRepository;
use App\Services\ConsoleStatsProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Tableaux de bord de pilotage : CFO (revenus, LTV, top clients, rentabilité) et
 * CEO (KPI stratégiques). Réutilisent ConsoleStatsProvider (DRY) et les agrégats
 * de ventes existants.
 */
#[Route('/console/crm', name: 'console.crm.')]
#[IsGranted('ROLE_ADMIN')]
class CrmFinanceController extends AbstractConsoleController
{
    public function __construct(
        private ConsoleStatsProvider $stats,
        private TokenPurchaseRepository $purchaseRepository,
        private CrmProfilRepository $profilRepository,
    ) {
    }

    #[Route('/cfo', name: 'cfo')]
    public function cfo(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $kpis = $this->stats->getKpis();
        $ltv = $kpis['nbClients'] > 0 ? $this->purchaseRepository->totals([])['revenue'] / $kpis['nbClients'] : 0.0;

        return $this->render('console/crm/cfo.html.twig', [
            'pageName'      => 'CRM — Tableau de bord CFO',
            'pageIcon'      => 'revenu',
            'kpis'          => $kpis,
            'ltv'           => $ltv,
            'topClients'    => $this->purchaseRepository->topClients(10),
            'chartVentesMois' => $this->stats->chartVentesParMois(),
            'chartVentesPays' => $this->stats->chartVentesParPays(),
        ]);
    }

    #[Route('/ceo', name: 'ceo')]
    public function ceo(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $kpis = $this->stats->getKpis();

        return $this->render('console/crm/ceo.html.twig', [
            'pageName'   => 'CRM — Tableau de bord CEO',
            'pageIcon'   => 'dashboard',
            'kpis'       => $kpis,
            'scoreMoyen' => round($this->profilRepository->averageScore()),
            'sante'      => $this->profilRepository->countByHealthColor(),
            'chartVentesMois' => $this->stats->chartVentesParMois(),
        ]);
    }
}
