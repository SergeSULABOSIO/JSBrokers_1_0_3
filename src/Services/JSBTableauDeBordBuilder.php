<?php

namespace App\Services;

use Symfony\Bundle\SecurityBundle\Security;


class JSBTableauDeBordBuilder
{
    private $dashboard = [];

    public function __construct(
        private Security $security,
        private ServiceDates $serviceDates,
        private JSBChartBuilder $JSBChartBuilder,
        private JSBTabBuilder $jSBTabBuilder,
        private JSBSummaryBuilder $jSBSummaryBuilder
    ) {}


    public function build(): self
    {
        //C'est ici que nous allons effectuer l'extraction de données de la base de données selon le filtre (le critère de selectioné fourni)
        $this->dashboard = [
            'productionCharts' => $this->JSBChartBuilder->getProductionCharts(),
            'productionTabs' => $this->jSBTabBuilder->getProductionTabs(),
            'productionSummaries' => $this->jSBSummaryBuilder->getProductionSummaries(),
        ];
        // $productionCharts = $this->JSBChartBuilder->getProductionCharts();
        // $productionTabs = $this->jSBTabBuilder->getProductionTabs();
        // $productionSummaries = $this->jSBSummaryBuilder->getProductionSummaries();
        return $this;
    }

    /**
     * Get the value of dashboard
     */ 
    public function getDashboard()
    {
        return $this->dashboard;
    }
}
