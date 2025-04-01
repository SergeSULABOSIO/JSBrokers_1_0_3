<?php

namespace App\Services;

use App\Constantes\Constante;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;


class JSBChartBuilder
{

    public function __construct(
        private Security $security,
        private ChartBuilderInterface $chartBuilder,
        private Constante $constante,
    ) {}

    public function newChartProductionPerMonth()
    {
        //Construction de l'histogramme
        $data = $this->constante->Entreprise_getDataProductionPerMonth();
        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $data['Mois'],
            'datasets' => [
                [
                    'label' => $data['Titre'],
                    'backgroundColor' => "gray", //'rgb(255, 99, 132)',
                    'borderColor' => 'white', //'rgb(255, 99, 132)',
                    'data' => $data['Montants'],
                ],
            ],
        ]);
        $chart->setOptions([
            'scales' => [
                'y' => $data['MinAndMax'],
            ],
        ]);

        return $chart;
    }

    public function newChartProductionPerInsurer()
    {
        //Construction de l'histogramme
        $chart = $this->chartBuilder->createChart(Chart::TYPE_PIE);
        $chart->setData([
            'labels' => [
                'SFA Congo SA (10%)',
                'SUNU ASSURANCE IARD (50%)',
                'ACTIVA  (20%)',
                'RAWSUR',
                'MAYFAIRE'
            ],
            'datasets' => [
                [
                    'label' => 'Insurers',
                    'backgroundColor' => [
                        'Red',
                        'Blue',
                        'Green',
                        'Grey',
                        'Orange'
                    ], //'rgb(255, 99, 132)',
                    'borderColor' => 'white', //'rgb(255, 99, 132)',
                    'data' => [1, 5, 2, 2, 9],
                    'hoverOffset' => 30,
                ],
            ],
        ]);

        return $chart;
    }

    public function newChartProductionPerRenewalStatus()
    {
        //Construction de l'histogramme
        $chart = $this->chartBuilder->createChart(Chart::TYPE_PIE);
        $chart->setData([
            'labels' => [
                'LOST',
                'ONCE-OFF',
                'RENEWED',
                'EXTENDED',
                'RUNNING'
            ],
            'datasets' => [
                [
                    'label' => 'Renewal Status',
                    'backgroundColor' => [
                        'Red',
                        'Blue',
                        'Green',
                        'Grey',
                        'Orange'
                    ], //'rgb(255, 99, 132)',
                    'borderColor' => 'white', //'rgb(255, 99, 132)',
                    'data' => [1, 5, 2, 2, 9],
                    'hoverOffset' => 30,
                ],
            ],
        ]);

        return $chart;
    }

    public function newChartProductionPerPartner()
    {
        //Construction de l'histogramme
        $chart = $this->chartBuilder->createChart(Chart::TYPE_PIE);
        $chart->setData([
            'labels' => [
                'OURSELVES',
                'OLEA',
                'MARSH',
                'MONT-BLANC',
                'AFINBRO',
                'AGL',
                "O'NEILS",
                "MERCER",
            ],
            'datasets' => [
                [
                    'label' => 'Broker Partners',
                    'backgroundColor' => [
                        'Red',
                        'Blue',
                        'Green',
                        'Grey',
                        'Orange',
                        'Pink',
                        'Magenta',
                        'Yellow',
                    ], //'rgb(255, 99, 132)',
                    'borderColor' => 'white', //'rgb(255, 99, 132)',
                    'data' => [1, 5, 2, 2, 9, 5, 8, 2],
                    'hoverOffset' => 30,
                ],
            ],
        ]);

        return $chart;
    }

    public function newChartProductionPerRisk()
    {
        //Construction de l'histogramme
        $chart = $this->chartBuilder->createChart(Chart::TYPE_PIE);
        $chart->setData([
            'labels' => [
                'FAP',
                'PVT',
                'PDBI',
                'MOTOR TPL',
                'CIT',
                'GIT',
                "GPA",
                "MEDICAL",
            ],
            'datasets' => [
                [
                    'label' => 'Line Of Business',
                    'backgroundColor' => [
                        'Red',
                        'Blue',
                        'Green',
                        'Grey',
                        'Orange',
                        'Pink',
                        'Magenta',
                        'Yellow',
                    ], //'rgb(255, 99, 132)',
                    'borderColor' => 'white', //'rgb(255, 99, 132)',
                    'data' => [1, 5, 2, 2, 9, 5, 8, 2],
                    'hoverOffset' => 30,
                ],
            ],
        ]);

        return $chart;
    }

    public function getProductionCharts(): array
    {
        return [
            'chartPerMonth' => $this->newChartProductionPerMonth(),
            'chartPerInsurer' => $this->newChartProductionPerInsurer(),
            'chartPerRenewalStatus' => $this->newChartProductionPerRenewalStatus(),
            'chartPerPartners' => $this->newChartProductionPerPartner(),
            'chartPerRisks' => $this->newChartProductionPerRisk(),
        ];
    }
}
