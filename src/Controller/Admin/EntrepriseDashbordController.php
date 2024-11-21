<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Constantes\MenuActivator;
use App\Entity\ReportSet;
use App\Services\JSBChartBuilder;
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
    public function dashbord(Entreprise $entreprise, Request $request, JSBChartBuilder $JSBChartBuilder)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $productionCharts = $JSBChartBuilder->getProductionCharts();

        $tabAssureurs = [
            "SFA Congo",
            "SUNU Assurance IARD",
            "RAWSUR SA",
            "ACTIVA",
            "MAYFAIR",
        ];
        $tabReportSets = [];
        for ($m = 1; $m <= 12; $m++) {
            $month = date('F', mktime(0, 0, 0, $m, 1, date('Y')));
            // echo $month . '<br>';
            $datasetMois = new ReportSet(
                ReportSet::TYPE_SUBTOTAL,
                "$",
                $month,
                3676011.63,
                3676011.63,
                3676011.63,
                3676011.63,
                3676011.63,
                3676011.63,
                3676011.63,
            );
            $tabReportSets[] = $datasetMois;
            foreach ($tabAssureurs as $assureur) {
                $datasetAssureur = new ReportSet(
                    ReportSet::TYPE_ELEMENT,
                    "$",
                    $assureur,
                    676.63,
                    676.63,
                    676.63,
                    676.63,
                    676.63,
                    676.63,
                    676.63,
                );
                $tabReportSets[] = $datasetAssureur;
            }
        }
        $datasetTotal = new ReportSet(
            ReportSet::TYPE_TOTAL,
            "$",
            "TOTAL",
            3676011.63,
            3676011.63,
            3676011.63,
            3676011.63,
            3676011.63,
            3676011.63,
            3676011.63,
        );
        $tabReportSets[] = $datasetTotal;
        // dd($tabReportSets);

        if ($user->isVerified()) {
            return $this->render('admin/dashbord/index.html.twig', [
                'pageName' => "Tableau de bord",
                'utilisateur' => $user,
                'entreprise' => $entreprise,
                'activator' => $this->activator,
                'page' => $request->query->getInt("page", 1),
                'productionCharts' => $productionCharts,
                'tabReportSets' => $tabReportSets,
            ]);
        } else {
            $this->addFlash("warning", "" . $user->getNom() . ", votre adresse mail n'est pas encore vérifiée. Veuillez cliquer sur le lien de vérification qui vous a été envoyé par JS Brokers à votre adresse " . $user->getEmail() . ".");
            return new RedirectResponse($this->urlGenerator->generate("app_login"));
        }
    }
}
