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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
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
    public function dashbord(Entreprise $entreprise, Request $request, JSBTableauDeBordBuilder $jSBTableauDeBordBuilder)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        //Initialisation du formulaire de recherche
        
        /** @var CriteresRechercheDashBordDTO */
        $data = new CriteresRechercheDashBordDTO();
        $formulaire_recherche = $this->createForm(RechercheDashBordType::class, $data);
        $formulaire_recherche->handleRequest($request);
        if ($formulaire_recherche->isSubmitted() && $formulaire_recherche->isValid()) {
            try {
                dd("La recherche est lancée!", $formulaire_recherche);

                //Lancer un évènement
                // $dispatcher->dispatch(new DemandeContactEvent($data));
                // $this->addFlash("success", $this->translator->trans("contact_email_sent_ok"));
            } catch (\Throwable $th) {
                //throw $th;
                // $this->addFlash("danger", $this->translator->trans("contact_email_sent_error"));
            }
            // return $this->redirectToRoute('admin.demande.contact.index');
        }

        $jSBTableauDeBordBuilder->build();

        if ($user->isVerified()) {
            return $this->render('admin/dashbord/index.html.twig', [
                'pageName' => "Tableau de bord",
                'utilisateur' => $user,
                'entreprise' => $entreprise,
                'activator' => $this->activator,
                'page' => $request->query->getInt("page", 1),
                'dashboard' => $jSBTableauDeBordBuilder->getDashboard(),
                'formulaire_recherche' => $formulaire_recherche,
            ]);
        } else {
            $this->addFlash("warning", "" . $user->getNom() . ", votre adresse mail n'est pas encore vérifiée. Veuillez cliquer sur le lien de vérification qui vous a été envoyé par JS Brokers à votre adresse " . $user->getEmail() . ".");
            return new RedirectResponse($this->urlGenerator->generate("app_login"));
        }
    }
}
