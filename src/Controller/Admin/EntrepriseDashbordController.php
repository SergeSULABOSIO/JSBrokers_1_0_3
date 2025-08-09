<?php

namespace App\Controller\Admin;

use DateTimeImmutable;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Constantes\MenuActivator;
use App\Form\RechercheDashBordType;
use App\DTO\CriteresRechercheDashBordDTO;
use App\Repository\EntrepriseRepository;
use App\Services\JSBTableauDeBordBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
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

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
    ) {
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request, JSBTableauDeBordBuilder $jSBTableauDeBordBuilder)
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Entreprise $ese */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        //on signale que le user s'est connecté à cette entreprise
        $user->setConnectedTo($entreprise);
        $this->manager->persist($user);
        $this->manager->flush();

        //Initialisation du formulaire de recherche
        /** @var CriteresRechercheDashBordDTO $criteres */
        $criteres = (new CriteresRechercheDashBordDTO())
            ->setDateDebut(new DateTimeImmutable("1/1/" . date('Y') . " 00:00"))
            ->setDateFin(new DateTimeImmutable("12/31/" . date('Y') . " 23:59"));

        $formulaire_recherche = $this->createForm(RechercheDashBordType::class, $criteres);

        $formulaire_recherche->handleRequest($request);

        // Très important de vérifier si le formulaire est soumis
        if ($formulaire_recherche->isSubmitted() && $formulaire_recherche->isValid()) {
            $jSBTableauDeBordBuilder->build($formulaire_recherche->getData());
        } else {
            $jSBTableauDeBordBuilder->build($criteres);
        }
        return $this->render('admin/dashbord/index.html.twig', [
            'pageName' => $this->translator->trans("company_dashboard_page_name"),
            'utilisateur' => $user,
            'entreprise' => $entreprise,
            // 'activator' => $this->activator,
            'page' => $request->query->getInt("page", 1),
            'dashboard' => $jSBTableauDeBordBuilder->getDashboard(),
            'formulaire_recherche' => $formulaire_recherche,
            'nbFiltresAvancesActif' => $criteres->nbFiltresAvancesActif(),
        ]);
    }
}
