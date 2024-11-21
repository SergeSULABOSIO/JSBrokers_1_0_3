<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Constantes\MenuActivator;
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

        $chartPerMonth = $JSBChartBuilder->newChartPerMonth();
        $chartPerInsurer = $JSBChartBuilder->newChartPerInsurer();
        $chartPerRenewalStatus = $JSBChartBuilder->newChartPerRenewalStatus();
        $chartPerPartners = $JSBChartBuilder->newChartPerPartner();
        $chartPerRisks = $JSBChartBuilder->newChartPerRisk();

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
}
