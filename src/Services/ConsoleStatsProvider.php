<?php

namespace App\Services;

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
        private EntrepriseRepository $entrepriseRepository,
        private TokenPurchaseRepository $purchaseRepository,
        private ChartBuilderInterface $chartBuilder,
        private ServiceGeographie $geographie,
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
            'nbClients'     => $this->utilisateurRepository->countClients(),
            'nbEntreprises' => $this->entrepriseRepository->countAllGlobal(),
            'nbVentes'      => $totals['count'],
            'tokensVendus'  => $totals['tokens'],
            'revenuUsd'     => $totals['revenue'],
            'remisesUsd'    => $totals['remises'],
        ];
    }

    /**
     * Histogramme du revenu des ventes par mois (janvier → décembre) de l'année
     * civile en cours.
     *
     * @return array{chart: Chart, titre: string}
     */
    public function chartVentesParMois(): array
    {
        $annee = (int) date('Y');
        $serie = $this->purchaseRepository->seriesParMoisAnnee($annee);

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
        $chart->setOptions([
            'responsive'          => true,
            'maintainAspectRatio' => false,
            'scales'              => ['y' => ['beginAtZero' => true]],
        ]);

        return ['chart' => $chart, 'titre' => sprintf('Revenu des ventes par mois (%d)', $annee)];
    }

    /**
     * Histogramme du revenu des ventes par pays pour l'année civile en cours.
     * Le pays d'une vente est dérivé de l'entreprise représentative de
     * l'acheteur (sa première entreprise détenue, à défaut l'entreprise active) ;
     * les ventes sans pays identifiable sont regroupées sous « Inconnu ».
     *
     * @return array{chart: Chart, titre: string}
     */
    public function chartVentesParPays(): array
    {
        $annee = (int) date('Y');

        $parPays = [];
        foreach ($this->purchaseRepository->findAnnee($annee) as $vente) {
            $acheteur   = $vente->getUtilisateur();
            $entreprise = null;
            if ($acheteur !== null) {
                $entreprise = $acheteur->getEntreprises()->first() ?: $acheteur->getConnectedTo();
            }

            $nom = 'Inconnu';
            if ($entreprise !== null && $entreprise->getPays() !== null) {
                $nom = $this->geographie->getNomPays($entreprise->getPays()) ?? 'Inconnu';
            }

            $parPays[$nom] = ($parPays[$nom] ?? 0.0) + (float) $vente->getMontantUsd();
        }

        arsort($parPays); // pays les plus contributeurs en tête

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => array_keys($parPays),
            'datasets' => [[
                'label'           => 'Revenu (USD)',
                'backgroundColor' => self::COLORS[0],
                'borderColor'     => 'white',
                'data'            => array_values($parPays),
            ]],
        ]);
        $chart->setOptions([
            'responsive'          => true,
            'maintainAspectRatio' => false,
            'scales'              => ['y' => ['beginAtZero' => true]],
        ]);

        return ['chart' => $chart, 'titre' => sprintf('Revenu des ventes par pays (%d)', $annee)];
    }

    /**
     * Camembert du revenu par paquet de tokens, pour l'année civile en cours.
     *
     * @return array{chart: Chart, titre: string}
     */
    public function chartVentesParPaquet(): array
    {
        $annee = (int) date('Y');
        $rows = $this->purchaseRepository->groupByPack([
            'from' => sprintf('%d-01-01', $annee),
            'to'   => sprintf('%d-12-31', $annee),
        ]);

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
        $chart->setOptions([
            'responsive'          => true,
            'maintainAspectRatio' => false,
        ]);

        return ['chart' => $chart, 'titre' => sprintf('Répartition du revenu par paquet (%d)', $annee)];
    }
}
