<?php

namespace App\Controller\Admin;

use DateTimeImmutable;
use App\Entity\ReportSet;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Services\JSBTabBuilder;
use App\Constantes\MenuActivator;
use App\Services\JSBChartBuilder;
use App\Form\RechercheDashBordType;
use App\Services\JSBSummaryBuilder;
use App\DTO\CriteresRechercheDashBordDTO;
use App\Services\JSBTableauDeBordBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Date;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/entreprise_dashbord", name: 'admin.entreprise.')]
#[IsGranted('ROLE_USER')]
class EntrepriseDashbordController extends AbstractController
{
    private MenuActivator $activator;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,

    ) {
        $this->activator = new MenuActivator(-1);
    }


    #[Route('/{id}', name: 'dashbord', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function dashbord(Entreprise $entreprise, Request $request, JSBTableauDeBordBuilder $jSBTableauDeBordBuilder)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        //Initialisation du formulaire de recherche
        /** @var CriteresRechercheDashBordDTO */
        $criteres = (new CriteresRechercheDashBordDTO())
            ->setDateDebut(new DateTimeImmutable("1/1/" . date('Y') . " 00:00"))
            ->setDateFin(new DateTimeImmutable("12/31/" . date('Y') . " 23:59"));

        $formulaire_recherche = $this->createForm(RechercheDashBordType::class, $criteres);

        $formulaire_recherche->handleRequest($request);
        
        // Pas important de vérifier si le formulaire est soumis
        if ($formulaire_recherche->isSubmitted() && $formulaire_recherche->isValid()) {
            $jSBTableauDeBordBuilder->build($formulaire_recherche->getData());
            return $this->render('admin/dashbord/index.html.twig', [
                'pageName' => $this->translator->trans("company_dashboard_page_name"),
                'utilisateur' => $user,
                'entreprise' => $entreprise,
                'activator' => $this->activator,
                'page' => $request->query->getInt("page", 1),
                'dashboard' => $jSBTableauDeBordBuilder->getDashboard(),
                'formulaire_recherche' => $formulaire_recherche,
                'nbFiltresAvancesActif' => $criteres->nbFiltresAvancesActif(),
            ]);
        } else {
            $jSBTableauDeBordBuilder->build($criteres);
        }

        if ($user->isVerified()) {
            return $this->render('admin/dashbord/index.html.twig', [
                'pageName' => $this->translator->trans("company_dashboard_page_name"),
                'utilisateur' => $user,
                'entreprise' => $entreprise,
                'activator' => $this->activator,
                'page' => $request->query->getInt("page", 1),
                'dashboard' => $jSBTableauDeBordBuilder->getDashboard(),
                'formulaire_recherche' => $formulaire_recherche,
                'nbFiltresAvancesActif' => $criteres->nbFiltresAvancesActif(),
            ]);
        } else {
            $this->addFlash("warning", $this->translator->trans("entreprise_your_email_is_not_verified", [
                ':user' => $user->getNom(),
                ':email' => $user->getEmail()
            ]));
            return new RedirectResponse($this->urlGenerator->generate("app_login"));
        }
    }
}
