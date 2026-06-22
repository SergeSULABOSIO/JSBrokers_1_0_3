<?php

namespace App\Services;

use App\Repository\ClientRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\TokenPurchaseRepository;
use App\Repository\UtilisateurRepository;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * @file Agrégats globaux de la plateforme pour le tableau de bord de la Console.
 * @description Centralise les KPIs (comptes, ventes, revenu, tokens) et la
 * construction des graphiques Chart.js (même bundle/pattern que JSBChartBuilder).
 * Lecture seule : ne modifie aucune donnée.
 */
class ConsoleStatsProvider
{
    /** Palette cohérente avec JSBChartBuilder, accent cobalt en tête. */
    private const COLORS = [
        'rgb(0,71,171)',    // cobalt (marque)
        'rgb(77,77,255)',
        'rgb(255,192,77)',
        'rgb(231,146,86)',
        'rgb(205,0,205)',
        'rgb(77,255,77)',
        'rgb(255,77,77)',
        'rgb(166,166,166)',
    ];

    public function __construct(
        private UtilisateurRepository $utilisateurRepository,
        private ClientRepository $clientRepository,
        private EntrepriseRepository $entrepriseRepository,
        private TokenPurchaseRepository $purchaseRepository,
        private ChartBuilderInterface $chartBuilder,
    ) {
    }

    /**
     * KPIs chiffrés du tableau de bord.
     *
     * @return array<string, int|float>
     */
    public function getKpis(): array
    {
        $totals = $this->purchaseRepository->totals();

        return [
            'nbAgents'      => count($this->utilisateurRepository->findAgents()),
            'nbUsers'       => $this->utilisateurRepository->countRegularUsers(),
            'nbClients'     => $this->clientRepository->countAll(),
            'nbEntreprises' => $this->entrepriseRepository->countAllGlobal(),
            'nbVentes'      => $totals['count'],
            'tokensVendus'  => $totals['tokens'],
            'revenuUsd'     => $totals['revenue'],
            'remisesUsd'    => $totals['remises'],
        ];
    }

    /**
     * Histogramme du revenu des ventes par mois (12 derniers mois).
     *
     * @return array{chart: Chart, titre: string}
     */
    public function chartVentesParMois(): array
    {
        $serie = $this->purchaseRepository->seriesParMois(12);

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $serie['labels'],
            'datasets' => [[
                'label'           => 'Revenu (USD)',
                'backgroundColor' => self::COLORS[0],
                'borderColor'     => 'white',
                'data'            => $serie['revenue'],
            ]],
        ]);
        $chart->setOptions(['scales' => ['y' => ['beginAtZero' => true]]]);

        return ['chart' => $chart, 'titre' => 'Revenu des ventes par mois'];
    }

    /**
     * Camembert du revenu par paquet de tokens.
     *
     * @return array{chart: Chart, titre: string}
     */
    public function chartVentesParPaquet(): array
    {
        $rows = $this->purchaseRepository->groupByPack();

        $chart = $this->chartBuilder->createChart(Chart::TYPE_PIE);
        $chart->setData([
            'labels' => array_map(static fn ($r) => ucfirst($r['pack']), $rows),
            'datasets' => [[
                'label'           => 'Revenu (USD)',
                'backgroundColor' => self::COLORS,
                'borderColor'     => 'white',
                'data'            => array_map(static fn ($r) => $r['revenue'], $rows),
                'hoverOffset'     => 30,
            ]],
        ]);

        return ['chart' => $chart, 'titre' => 'Répartition du revenu par paquet'];
    }
}
