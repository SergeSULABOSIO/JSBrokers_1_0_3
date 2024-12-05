<?php

namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Constantes\MenuActivator;
use App\DTO\CriteresRechercheDashBordDTO;
use App\Entity\ReportSet;
use App\Form\RechercheDashBordType;
use App\Services\JSBChartBuilder;
use App\Services\JSBSummaryBuilder;
use App\Services\JSBTabBuilder;
use App\Services\JSBTableauDeBordBuilder;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Validator\Constraints\Date;

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
            // dd($formulaire_recherche->getData("entreprises")->getData());
            // dd($formulaire_recherche->getData() == $criteres);
            // return new RedirectResponse($this->urlGenerator->generate("app_login"));
            $nbActif = $criteres->nbFiltresAvancesActif();
            return $this->render('admin/dashbord/index.html.twig', [
                'pageName' => "Tableau de bord",
                'utilisateur' => $user,
                'entreprise' => $entreprise,
                'activator' => $this->activator,
                'page' => $request->query->getInt("page", 1),
                'dashboard' => $jSBTableauDeBordBuilder->getDashboard(),
                'formulaire_recherche' => $formulaire_recherche,
                'nbFiltresAvancesActif' => $nbActif,
            ]);
            // return $this->redirectToRoute('admin.entreprise.dashbord', [
            //     'id' => $entreprise->getId(),
            // ]);
        } else {
            $jSBTableauDeBordBuilder->build($criteres);
        }

        if ($user->isVerified()) {
            return $this->render('admin/dashbord/index.html.twig', [
                'pageName' => "Tableau de bord",
                'utilisateur' => $user,
                'entreprise' => $entreprise,
                'activator' => $this->activator,
                'page' => $request->query->getInt("page", 1),
                'dashboard' => $jSBTableauDeBordBuilder->getDashboard(),
                'formulaire_recherche' => $formulaire_recherche,
                'nbFiltresAvancesActif' => $criteres->nbFiltresAvancesActif(),
            ]);
        } else {
            $this->addFlash("warning", "" . $user->getNom() . ", votre adresse mail n'est pas encore vérifiée. Veuillez cliquer sur le lien de vérification qui vous a été envoyé par JS Brokers à votre adresse " . $user->getEmail() . ".");
            return new RedirectResponse($this->urlGenerator->generate("app_login"));
        }
    }
}
