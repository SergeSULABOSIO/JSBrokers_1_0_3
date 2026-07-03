<?php

namespace App\Controller\Admin;

use DateTimeImmutable;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Form\RechercheDashBordType;
use App\DTO\CriteresRechercheDashBordDTO;
use App\Repository\EntrepriseRepository;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\DashboardDataProvider;
use App\Services\JSBTableauDeBordBuilder;
use App\Services\ServiceMonnaies;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/entreprise_dashbord", name: 'admin.entreprise_dashboard.')]
#[IsGranted('ROLE_USER')]
class EntrepriseDashbordController extends AbstractController
{

    public function __construct(
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private WorkspaceAccessResolver $accessResolver,
    ) {
    }

    /**
     * Garde de bloc du tableau de bord : renvoie une réponse vide (le bloc lazy reste
     * simplement vide, sans erreur JS) si l'invité connecté n'a pas la lecture sur
     * l'entité de référence du bloc. Défense en profondeur — le Twig masque déjà le bloc.
     * Le propriétaire de l'entreprise passe toujours (bypass du resolver).
     */
    private function denyBlockIfCannotRead(string $entityShortName): ?Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $invite = $this->accessResolver->resolveConnectedInvite($user);
        if ($invite === null || !$this->accessResolver->canRead($invite, $entityShortName)) {
            return new Response(''); // 200 vide : le conteneur lazy-block reste inerte.
        }

        return null;
    }


    #[Route('/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(int $idEntreprise, Request $request, JSBTableauDeBordBuilder $jSBTableauDeBordBuilder)
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

    #[Route('/workspace/{idEntreprise}', name: 'workspace', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function loadWorkspaceComponent(int $idEntreprise): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        return $this->render('components/_tableau_de_bord_component.html.twig', [
            'utilisateur' => $user,
            'entreprise'  => $entreprise,
        ]);
    }

    #[Route('/block/kpis/{idEntreprise}', name: 'block_kpis', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockKpis(int $idEntreprise, DashboardDataProvider $provider, ServiceMonnaies $serviceMonnaies): Response
    {
        if ($denied = $this->denyBlockIfCannotRead('Note')) { return $denied; }
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        $debut = new DateTimeImmutable("1/1/" . date('Y') . " 00:00");
        $fin   = new DateTimeImmutable("12/31/" . date('Y') . " 23:59");

        return $this->render('components/dashboard/_block_kpis.html.twig', [
            'entreprise'       => $entreprise,
            'paiementsTotaux'  => $provider->getPaiementsTotaux($entreprise, $debut, $fin),
            'policiesActives'  => $provider->getPoliciesActives($entreprise),
            'primesTotales'    => $provider->getPrimesTotales($entreprise),
            'retrocommissions' => $provider->getRetrocommissionsTotales($entreprise),
            'taxes'            => $provider->getTaxesTotales($entreprise),
            'commissions'      => $provider->getCommissionsTotales($entreprise),
            'revenusBreakdown' => $provider->getRevenusPercusBreakdown($entreprise),
            'deviseCode'       => $serviceMonnaies->getCodeMonnaieAffichage() ?? '€',
        ]);
    }

    /**
     * Bloc « Comptabilité » : indicateurs comptables du cabinet sur l'exercice en
     * cours, dérivés de la MÊME source que les Documents comptables
     * (CourtierEcritureComptableService) — cohérence garantie : commissions
     * encaissées (produits), charges, résultat net, décaissements et trésorerie,
     * plus le pouls des dépenses (payées / engagées) et des fournisseurs actifs.
     * Gate : lecture des Dépenses (périmètre Finances).
     */
    #[Route('/block/compta/{idEntreprise}', name: 'block_compta', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockCompta(
        int $idEntreprise,
        \App\Comptabilite\CourtierEcritureComptableService $courtierComptabilite,
        \App\Repository\DepenseCourtierRepository $depenseRepository,
        \App\Repository\FournisseurRepository $fournisseurRepository,
        ServiceMonnaies $serviceMonnaies,
    ): Response {
        if ($denied = $this->denyBlockIfCannotRead('DepenseCourtier')) { return $denied; }
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        $exercice = (int) date('Y');

        $documents = $courtierComptabilite->documents($entreprise, $exercice);

        // Pouls des dépenses de l'exercice (non annulées, déjà filtrées par la requête).
        $depensesPayees = 0;
        $depensesEngagees = 0;
        foreach ($depenseRepository->findChronologiqueForEntreprise($idEntreprise) as $depense) {
            if ((int) $depense->getDateDepense()?->format('Y') !== $exercice) {
                continue;
            }
            if ($depense->getStatut() === \App\Entity\Depense::STATUT_PAYEE) {
                $depensesPayees++;
            } else {
                $depensesEngagees++;
            }
        }

        return $this->render('components/dashboard/_block_compta.html.twig', [
            'entreprise'        => $entreprise,
            'exercice'          => $exercice,
            'resultat'          => $documents['resultat'],
            'tft'               => $documents['tft'],
            'depensesPayees'    => $depensesPayees,
            'depensesEngagees'  => $depensesEngagees,
            'fournisseursActifs' => $fournisseurRepository->count(['entreprise' => $entreprise, 'actif' => true]),
            'deviseCode'        => $serviceMonnaies->getCodeMonnaieAffichage() ?? 'USD',
        ]);
    }

    #[Route('/block/renewals/{idEntreprise}', name: 'block_renewals', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockRenewals(int $idEntreprise, DashboardDataProvider $provider, ServiceMonnaies $serviceMonnaies): Response
    {
        if ($denied = $this->denyBlockIfCannotRead('Avenant')) { return $denied; }
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        return $this->render('components/dashboard/_renewals.html.twig', [
            'entreprise'      => $entreprise,
            'renouvellements' => $provider->getAllRenouvellements($entreprise),
            'deviseCode'      => $serviceMonnaies->getCodeMonnaieAffichage() ?? '€',
        ]);
    }

    #[Route('/renewals-fragment/{idEntreprise}', name: 'renewals_fragment', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadRenewalsFragment(int $idEntreprise, DashboardDataProvider $provider, ServiceMonnaies $serviceMonnaies): Response
    {
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        return $this->render('components/dashboard/_renewals_list.html.twig', [
            'entreprise'      => $entreprise,
            'renouvellements' => $provider->getAllRenouvellements($entreprise),
            'deviseCode'      => $serviceMonnaies->getCodeMonnaieAffichage() ?? '€',
        ]);
    }

    #[Route('/block/encaissements/{idEntreprise}', name: 'block_encaissements', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockEncaissements(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        if ($denied = $this->denyBlockIfCannotRead('Paiement')) { return $denied; }
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        return $this->render('components/dashboard/_encaissements.html.twig', [
            'entreprise'           => $entreprise,
            'derniersEncaissements' => $provider->getDerniersEncaissements($entreprise),
        ]);
    }

    #[Route('/encaissements-fragment/{idEntreprise}', name: 'encaissements_fragment', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadEncaissementsFragment(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        return $this->render('components/dashboard/_encaissements_list.html.twig', [
            'derniersEncaissements' => $provider->getDerniersEncaissements($entreprise),
        ]);
    }

    #[Route('/block/sinistres/{idEntreprise}', name: 'block_sinistres', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockSinistres(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        if ($denied = $this->denyBlockIfCannotRead('NotificationSinistre')) { return $denied; }
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        return $this->render('components/dashboard/_sinistres.html.twig', [
            'entreprise'        => $entreprise,
            'derniersSinistres' => $provider->getDerniersSinistres($entreprise),
        ]);
    }

    #[Route('/sinistres-fragment/{idEntreprise}', name: 'sinistres_fragment', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadSinistresFragment(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        return $this->render('components/dashboard/_sinistres_list.html.twig', [
            'derniersSinistres' => $provider->getDerniersSinistres($entreprise),
            'entreprise'        => $entreprise,
        ]);
    }

    #[Route('/block/tasks/{idEntreprise}', name: 'block_tasks', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockTasks(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        return $this->render('components/dashboard/_block_tasks.html.twig', [
            'utilisateur'      => $user,
            'entreprise'       => $entreprise,
            'derniersFeedbacks' => $provider->getDerniersFeedbacks($entreprise),
            'taches'           => $provider->getTachesNonCloses($entreprise),
        ]);
    }

    #[Route('/block/users/{idEntreprise}', name: 'block_users', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockUsers(int $idEntreprise): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        return $this->render('components/dashboard/_block_users.html.twig', [
            'utilisateur' => $user,
            'entreprise'  => $entreprise,
        ]);
    }

    #[Route('/users-fragment/{idEntreprise}', name: 'users_fragment', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadUsersFragment(int $idEntreprise): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        return $this->render('components/dashboard/_users_list.html.twig', [
            'utilisateur' => $user,
            'entreprise'  => $entreprise,
        ]);
    }

    #[Route('/block/top-lists/{idEntreprise}', name: 'block_top_lists', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockTopLists(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        return $this->render('components/dashboard/_block_top_lists.html.twig', [
            'utilisateur'       => $user,
            'entreprise'        => $entreprise,
            'topAssureurs'      => $provider->getTopAssureursAvecIndicateurs($entreprise),
            'topAssures'        => $provider->getTopAssuresAvecIndicateurs($entreprise),
            'topRisques'        => $provider->getTopRisquesAvecIndicateurs($entreprise),
            'topIntermediaires' => $provider->getTopIntermediairesAvecIndicateurs($entreprise),
        ]);
    }

    #[Route('/sidebar/{idEntreprise}', name: 'sidebar', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadSidebarFragment(int $idEntreprise): Response
    {
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        return $this->render('components/dashboard/_sidebar.html.twig', [
            'entreprise' => $entreprise,
        ]);
    }

    #[Route('/feedbacks-fragment/{idEntreprise}', name: 'feedbacks_fragment', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadFeedbacksFragment(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        return $this->render('components/dashboard/_feedbacks_list.html.twig', [
            'derniersFeedbacks' => $provider->getDerniersFeedbacks($entreprise),
        ]);
    }

    #[Route('/block/pistes/{idEntreprise}', name: 'block_pistes', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockPistes(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        if ($denied = $this->denyBlockIfCannotRead('Piste')) { return $denied; }
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        return $this->render('components/dashboard/_block_pistes.html.twig', [
            'utilisateur' => $user,
            'entreprise'  => $entreprise,
            'pistes'      => $provider->getPistesEnCours($entreprise),
        ]);
    }

    #[Route('/pistes-fragment/{idEntreprise}', name: 'pistes_fragment', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadPistesFragment(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        return $this->render('components/dashboard/_pistes_list.html.twig', [
            'utilisateur' => $user,
            'entreprise'  => $entreprise,
            'pistes'      => $provider->getPistesEnCours($entreprise),
        ]);
    }

    #[Route('/block/bordereaux/{idEntreprise}', name: 'block_bordereaux', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockBordereaux(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        if ($denied = $this->denyBlockIfCannotRead('Bordereau')) { return $denied; }
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        return $this->render('components/dashboard/_block_bordereaux.html.twig', [
            'utilisateur' => $user,
            'entreprise'  => $entreprise,
            'bordereaux'  => $provider->getDerniersBordereaux($entreprise),
        ]);
    }

    #[Route('/block/notes/{idEntreprise}', name: 'block_notes', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockNotes(int $idEntreprise, DashboardDataProvider $provider, ServiceMonnaies $serviceMonnaies): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        if ($denied = $this->denyBlockIfCannotRead('Note')) { return $denied; }
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        return $this->render('components/dashboard/_block_notes.html.twig', [
            'utilisateur' => $user,
            'entreprise'  => $entreprise,
            'notes'       => $provider->getDerniersNotes($entreprise),
            'deviseCode'  => $serviceMonnaies->getCodeMonnaieAffichage() ?? '$',
        ]);
    }

    #[Route('/notes-fragment/{idEntreprise}', name: 'notes_fragment', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadNotesFragment(int $idEntreprise, DashboardDataProvider $provider, ServiceMonnaies $serviceMonnaies): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        return $this->render('components/dashboard/_notes_list.html.twig', [
            'utilisateur' => $user,
            'entreprise'  => $entreprise,
            'notes'       => $provider->getDerniersNotes($entreprise),
            'deviseCode'  => $serviceMonnaies->getCodeMonnaieAffichage() ?? '$',
        ]);
    }

    #[Route('/bordereaux-fragment/{idEntreprise}', name: 'bordereaux_fragment', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBordereauFragment(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        return $this->render('components/dashboard/_bordereaux_list.html.twig', [
            'utilisateur' => $user,
            'entreprise'  => $entreprise,
            'bordereaux'  => $provider->getDerniersBordereaux($entreprise),
        ]);
    }

    #[Route('/block/production/{idEntreprise}', name: 'block_production', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockProduction(int $idEntreprise, DashboardDataProvider $provider, ServiceMonnaies $serviceMonnaies): Response
    {
        if ($denied = $this->denyBlockIfCannotRead('Avenant')) { return $denied; }
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        $monthly = $provider->getProductionMensuelle($entreprise);

        return $this->render('components/dashboard/_block_production.html.twig', [
            'entreprise'   => $entreprise,
            'monthly'      => array_values($monthly),
            'year'         => (int) date('Y'),
            'deviseCode'   => $serviceMonnaies->getCodeMonnaieAffichage() ?? '€',
            'prodDataUrl'  => $this->generateUrl('admin.entreprise_dashboard.production_data',   ['idEntreprise' => $idEntreprise]),
            'tableDataUrl' => $this->generateUrl('admin.entreprise_dashboard.production_table',  ['idEntreprise' => $idEntreprise]),
            'groupUrl'     => $this->generateUrl('admin.entreprise_dashboard.production_group',  ['idEntreprise' => $idEntreprise]),
        ]);
    }

    #[Route('/production-data/{idEntreprise}', name: 'production_data', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadProductionData(int $idEntreprise, DashboardDataProvider $provider, ServiceMonnaies $serviceMonnaies): JsonResponse
    {
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        $monthly = $provider->getProductionMensuelle($entreprise);

        return new JsonResponse([
            'monthly'  => array_values($monthly),
            'year'     => (int) date('Y'),
            'currency' => $serviceMonnaies->getCodeMonnaieAffichage() ?? '€',
            'total'    => array_sum($monthly),
        ]);
    }

    #[Route('/production-table/{idEntreprise}', name: 'production_table', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadProductionTableData(int $idEntreprise, DashboardDataProvider $provider, ServiceMonnaies $serviceMonnaies): JsonResponse
    {
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        $data = $provider->getProductionTableData($entreprise);
        $data['currency'] = $serviceMonnaies->getCodeMonnaieAffichage() ?? '€';
        return new JsonResponse($data);
    }

    #[Route('/production-group/{idEntreprise}', name: 'production_group', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function productionGroup(int $idEntreprise, DashboardDataProvider $provider, ServiceMonnaies $serviceMonnaies): JsonResponse
    {
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        $data = $provider->getProductionGroupData($entreprise);
        $data['currency'] = $serviceMonnaies->getCodeMonnaieAffichage() ?? '€';
        return new JsonResponse($data);
    }
}
