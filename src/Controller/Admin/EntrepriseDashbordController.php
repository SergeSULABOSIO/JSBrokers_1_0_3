<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Constantes\MenuActivator;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/entreprise_dashbord", name: 'admin.entreprise.')]
#[IsGranted('ROLE_USER')]
class EntrepriseDashbordController extends AbstractController
{
    private MenuActivator $activator;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator
    ) {
        $this->activator = new MenuActivator(-1);
    }


    #[Route('/{id}', name: 'dashbord', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function dashbord(Entreprise $entreprise, Request $request, ChartBuilderInterface $chartBuilder)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // dd("Je lance la tableau de bord - entreprise", $entreprise);
        // dd($entreprise, $user);

        $chartPerMonth = $this->getChartPerMonth($chartBuilder);
        $chartPerInsurer = $this->getChartPerInsurer($chartBuilder);
        $chartPerRenewalStatus = $this->getChartPerRenewalStatus($chartBuilder);
        $chartPerPartners = $this->getChartPerPartners($chartBuilder);
        $chartPerRisks = $this->getChartPerRisk($chartBuilder);

        if ($user->isVerified()) {
            return $this->render('admin/dashbord/index.html.twig', [
                'pageName' => "Tableau de bord",
                'utilisateur' => $user,
                'entreprise' => $entreprise,
                'activator' => $this->activator,
                'page' => $request->query->getInt("page", 1),
                'chartPerMonth' => $chartPerMonth,
                'chartPerInsurer' => $chartPerInsurer,
                'chartPerRenewalStatus' => $chartPerRenewalStatus,
                'chartPerPartners' => $chartPerPartners,
                'chartPerRisks' => $chartPerRisks,
            ]);
        } else {
            $this->addFlash("warning", "" . $user->getNom() . ", votre adresse mail n'est pas encore vérifiée. Veuillez cliquer sur le lien de vérification qui vous a été envoyé par JS Brokers à votre adresse " . $user->getEmail() . ".");
            return new RedirectResponse($this->urlGenerator->generate("app_login"));
        }
    }


    public function getChartPerMonth(ChartBuilderInterface $chartBuilder)
    {
        //Construction de l'histogramme
        $chart = $chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => [
                'January', 
                'February', 
                'March', 
                'April', 
                'May', 
                'June', 
                'July',
                'August',
                'September',
                'October',
                'November',
                'December',
            ],
            'datasets' => [
                [
                    'label' => 'Revenue',
                    'backgroundColor' => "gray",//'rgb(255, 99, 132)',
                    'borderColor' => 'black', //'rgb(255, 99, 132)',
                    'data' => [0, 10, 50, 2, 20, 90, 45, 25, 15, 60, 5, 90],
                ],
            ],
        ]);
        $chart->setOptions([
            'scales' => [
                'y' => [
                    'suggestedMin' => 0,
                    'suggestedMax' => 100,
                ],
            ],
        ]);

        return $chart;
    }

    public function getChartPerInsurer(ChartBuilderInterface $chartBuilder)
    {
        //Construction de l'histogramme
        $chart = $chartBuilder->createChart(Chart::TYPE_PIE);
        $chart->setData([
            'labels' => [
                'SFA Congo SA', 
                'SUNU ASSURANCE IARD', 
                'ACTIVA', 
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
                    ],//'rgb(255, 99, 132)',
                    'borderColor' => 'Black', //'rgb(255, 99, 132)',
                    'data' => [1, 5, 2, 2, 9],
                    'hoverOffset' => 30,
                ],
            ],
        ]);

        return $chart;
    }

    public function getChartPerRenewalStatus(ChartBuilderInterface $chartBuilder)
    {
        //Construction de l'histogramme
        $chart = $chartBuilder->createChart(Chart::TYPE_PIE);
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
                    ],//'rgb(255, 99, 132)',
                    'borderColor' => 'Black', //'rgb(255, 99, 132)',
                    'data' => [1, 5, 2, 2, 9],
                    'hoverOffset' => 30,
                ],
            ],
        ]);

        return $chart;
    }

    public function getChartPerPartners(ChartBuilderInterface $chartBuilder)
    {
        //Construction de l'histogramme
        $chart = $chartBuilder->createChart(Chart::TYPE_PIE);
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
                    ],//'rgb(255, 99, 132)',
                    'borderColor' => 'Black', //'rgb(255, 99, 132)',
                    'data' => [1, 5, 2, 2, 9, 5, 8, 2],
                    'hoverOffset' => 30,
                ],
            ],
        ]);

        return $chart;
    }

    public function getChartPerRisk(ChartBuilderInterface $chartBuilder)
    {
        //Construction de l'histogramme
        $chart = $chartBuilder->createChart(Chart::TYPE_PIE);
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
                    ],//'rgb(255, 99, 132)',
                    'borderColor' => 'Black', //'rgb(255, 99, 132)',
                    'data' => [1, 5, 2, 2, 9, 5, 8, 2],
                    'hoverOffset' => 30,
                ],
            ],
        ]);

        return $chart;
    }
}
