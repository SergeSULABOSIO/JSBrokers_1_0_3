<?php

namespace App\Services;

use App\Constantes\Constante;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;


class JSBChartBuilder
{

    public $backgroundColors = [
        'rgb(166,166,166)', //'Grey',
        'rgb(77,77,255)', //'Blue',
        'rgb(231,146,86)', //'Chocolate',
        'rgb(255,77,77)', //'Red',
        'rgb(205,0,205)', //'Purple',
        'rgb(255,192,77)', //'Orange',
        'rgb(209,74,74)', //'Brown',
        'rgb(255,243,245)', //'Pink',
        'rgb(77,255,77)', //'Green',
        //'rgb(255, 99, 132)'
    ];

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

        return [
            'chart' => $chart,
            'notes' => $data['Notes'],
            'titre' => $data['Titre'],
        ];
        // return $chart;
    }

    public function newChartProductionPerInsurer()
    {
        //Construction de l'histogramme
        $data = $this->constante->Entreprise_getDataProductionPerInsurer();
        $chart = $this->chartBuilder->createChart(Chart::TYPE_PIE);
        $chart->setData([
            'labels' => $data['Assureurs'],
            'datasets' => [
                [
                    'label' => $data['Titre'],
                    'backgroundColor' => $this->backgroundColors,
                    'borderColor' => 'white', //'rgb(255, 99, 132)',
                    'data' => $data['Montants'],
                    'hoverOffset' => 30,
                ],
            ],
        ]);

        return [
            'chart' => $chart,
            'notes' => $data['Notes'],
            'titre' => $data['Titre'],
        ];
        // return $chart;
    }

    public function newChartProductionPerRenewalStatus()
    {
        //Construction de l'histogramme
        $data = $this->constante->Entreprise_getDataProductionPerRenewalStatus();
        $chart = $this->chartBuilder->createChart(Chart::TYPE_PIE);
        $chart->setData([
            'labels' => $data['Renewal Status Definitions'],
            'datasets' => [
                [
                    'label' => $data['Titre'],
                    'backgroundColor' => $this->backgroundColors,
                    'borderColor' => 'white', //'rgb(255, 99, 132)',
                    'data' => $data['Montants'],
                    'hoverOffset' => 30,
                ],
            ],
        ]);
        return [
            'chart' => $chart,
            'notes' => $data['Notes'],
            'titre' => $data['Titre'],
        ];
        // return $chart;
    }

    public function newChartProductionPerPartner()
    {
        //Construction de l'histogramme
        $data = $this->constante->Entreprise_getDataProductionPerPartner();
        $chart = $this->chartBuilder->createChart(Chart::TYPE_PIE);
        $chart->setData([
            'labels' => $data['Partners'],
            'datasets' => [
                [
                    'label' => $data['Titre'],
                    'backgroundColor' => $this->backgroundColors,
                    'borderColor' => 'white', //'rgb(255, 99, 132)',
                    'data' => $data['Montants'],
                    'hoverOffset' => 30,
                ],
            ],
        ]);

        return [
            'chart' => $chart,
            'notes' => $data['Notes'],
            'titre' => $data['Titre'],
        ];
        // return $chart;
    }

    public function newChartProductionPerRisk()
    {
        //Construction de l'histogramme
        $data = $this->constante->Entreprise_getDataProductionPerRisk();
        $chart = $this->chartBuilder->createChart(Chart::TYPE_PIE);
        $chart->setData([
            'labels' => $data['Risks'],
            'datasets' => [
                [
                    'label' => $data['Titre'],
                    'backgroundColor' => $this->backgroundColors,
                    'borderColor' => 'white', //'rgb(255, 99, 132)',
                    'data' => $data['Montants'],
                    'hoverOffset' => 30,
                ],
            ],
        ]);
        return [
            'chart' => $chart,
            'notes' => $data['Notes'],
            'titre' => $data['Titre'],
        ];
        // return $chart;
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
