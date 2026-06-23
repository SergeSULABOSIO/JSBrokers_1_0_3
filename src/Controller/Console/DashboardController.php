<?php

namespace App\Controller\Console;

use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Repository\CouponRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\TokenConsumptionRepository;
use App\Repository\TokenPurchaseRepository;
use App\Repository\UtilisateurRepository;
use App\Services\ConsoleStatsProvider;
use App\Services\ServiceGeographie;
use App\Services\ServiceTaxesVente;
use App\Token\ParametresTokenService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Tableau de bord global de la Console JS Brokers.
 *
 * Calqué sur le tableau de bord de l'espace de travail courtiers : une pile de
 * blocs pliables (un par ligne), chacun chargé à la demande puis rafraîchi
 * toutes les 3 minutes. La page (index) ne rend que la structure ; chaque bloc
 * est servi par un point d'entrée dédié appelé en AJAX par le contrôleur
 * Stimulus « lazy-block » (KPIs/graphiques) ou « dashboard-list » (ventes).
 */
#[Route('/console')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractConsoleController
{
    public function __construct(
        private ConsoleStatsProvider $stats,
        private TokenPurchaseRepository $purchaseRepository,
        private EntrepriseRepository $entrepriseRepository,
        private UtilisateurRepository $utilisateurRepository,
        private TokenConsumptionRepository $consumptionRepository,
        private ServiceGeographie $geographie,
        private CouponRepository $couponRepository,
        private ParametresTokenService $parametres,
        private ServiceTaxesVente $taxesVente,
    ) {}

    #[Route('', name: 'console.dashboard', methods: ['GET'])]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->render('console/dashboard.html.twig', [
            'pageName' => 'Tableau de bord',
            // KPIs rendus dès le chargement (toujours visibles, sans cadre) ;
            // ils sont ensuite rafraîchis silencieusement côté client.
            'kpis'     => $this->stats->getKpis(),
        ]);
    }

    #[Route('/dashboard/block/kpis', name: 'console.dashboard.block_kpis', methods: ['GET'])]
    public function blockKpis(): Response
    {
        return $this->render('console/dashboard/_block_kpis.html.twig', [
            'kpis' => $this->stats->getKpis(),
        ]);
    }

    #[Route('/dashboard/block/revenue', name: 'console.dashboard.block_revenue', methods: ['GET'])]
    public function blockRevenue(): Response
    {
        // Bloc fusionné : les deux vues du revenu (par mois / par paquet) sont
        // rendues ensemble, l'affichage de l'une ou l'autre étant piloté côté
        // client par les boutons de bascule (contrôleur Stimulus « chart-modes »).
        return $this->render('console/dashboard/_block_revenue.html.twig', [
            'chartVentesMois' => $this->stats->chartVentesParMois(),
            'chartVentesPack' => $this->stats->chartVentesParPaquet(),
            'chartVentesPays' => $this->stats->chartVentesParPays(),
        ]);
    }

    #[Route('/dashboard/block/ventes', name: 'console.dashboard.block_ventes', methods: ['GET'])]
    public function blockVentes(): Response
    {
        return $this->render('console/dashboard/_block_ventes.html.twig', $this->donneesVentes());
    }

    #[Route('/dashboard/ventes-fragment', name: 'console.dashboard.ventes_fragment', methods: ['GET'])]
    public function ventesFragment(): Response
    {
        return $this->render('console/dashboard/_ventes_list.html.twig', $this->donneesVentes());
    }

    /**
     * Dernières ventes + localisation (ville/pays) dérivée de l'entreprise de
     * l'acheteur. La vente n'a pas de localisation propre : on prend celle de
     * l'entreprise représentative du compte acheteur (cf. entrepriseRepresentative()).
     *
     * @return array{dernieresVentes: \Knp\Component\Pager\Pagination\PaginationInterface, geo: array<int,array{pays:?string, ville:?string}>}
     */
    private function donneesVentes(): array
    {
        $ventes = $this->purchaseRepository->paginateFiltered([], 1);

        $geo = [];
        foreach ($ventes->getItems() as $v) {
            $geo[$v->getId()] = $this->localisationDe(
                $this->entrepriseRepresentative($v->getUtilisateur()),
            );
        }

        return ['dernieresVentes' => $ventes, 'geo' => $geo];
    }

    /**
     * Entreprise représentative d'un compte : sa première entreprise détenue,
     * à défaut l'entreprise active (connectedTo). Sert à dériver une
     * localisation pour un utilisateur/une vente qui n'en portent pas.
     */
    private function entrepriseRepresentative(?Utilisateur $u): ?Entreprise
    {
        if (!$u instanceof Utilisateur) {
            return null;
        }

        $owned = $u->getEntreprises()->first();

        return $owned instanceof Entreprise ? $owned : $u->getConnectedTo();
    }

    /**
     * Libellés ville/pays d'une entreprise (identifiants → noms via le service
     * de géographie, source de vérité = pays_villes.json).
     *
     * @return array{pays:?string, ville:?string}
     */
    private function localisationDe(?Entreprise $e): array
    {
        if (!$e instanceof Entreprise) {
            return ['pays' => null, 'ville' => null];
        }

        $paysId  = $e->getPays();
        $villeId = $e->getVille();

        return [
            'pays'  => $paysId !== null ? $this->geographie->getNomPays($paysId) : null,
            'ville' => $villeId !== null ? $this->geographie->getNomVille($villeId) : null,
        ];
    }

    #[Route('/dashboard/block/entreprises', name: 'console.dashboard.block_entreprises', methods: ['GET'])]
    public function blockEntreprises(): Response
    {
        return $this->render('console/dashboard/_block_entreprises.html.twig', $this->donneesEntreprises());
    }

    #[Route('/dashboard/entreprises-fragment', name: 'console.dashboard.entreprises_fragment', methods: ['GET'])]
    public function entreprisesFragment(): Response
    {
        return $this->render('console/dashboard/_entreprises_list.html.twig', $this->donneesEntreprises());
    }

    /**
     * Dernières entreprises + consommation cumulée de tokens par entreprise
     * (un seul agrégat pour toute la page) ; le solde restant est lu sur le
     * propriétaire dans le gabarit.
     *
     * @return array{dernieresEntreprises: \Knp\Component\Pager\Pagination\PaginationInterface, consommations: array<int,int>, geo: array<int,array{pays:?string, ville:?string}>}
     */
    private function donneesEntreprises(): array
    {
        $entreprises = $this->entrepriseRepository->paginateAll(1, 20);
        $ids = array_map(static fn ($e) => $e->getId(), $entreprises->getItems());

        // Pays/ville stockés sous forme d'identifiants : on résout les libellés
        // via le service de géographie (source de vérité = pays_villes.json).
        $geo = [];
        foreach ($entreprises->getItems() as $e) {
            $geo[$e->getId()] = $this->localisationDe($e);
        }

        return [
            'dernieresEntreprises' => $entreprises,
            'consommations'        => $this->consumptionRepository->totauxParEntreprises($ids),
            'geo'                  => $geo,
        ];
    }

    #[Route('/dashboard/block/clients', name: 'console.dashboard.block_clients', methods: ['GET'])]
    public function blockClients(): Response
    {
        return $this->render('console/dashboard/_block_clients.html.twig', $this->donneesClients());
    }

    #[Route('/dashboard/clients-fragment', name: 'console.dashboard.clients_fragment', methods: ['GET'])]
    public function clientsFragment(): Response
    {
        return $this->render('console/dashboard/_utilisateurs_list.html.twig', $this->donneesClients());
    }

    /**
     * Derniers clients : comptes en mode payant (solde prépayé > 0), tous rôles
     * confondus. Réutilise la liste générique des comptes (clé derniersUtilisateurs)
     * avec un message vide adapté ; consommation cumulée comme payeur en agrégat.
     *
     * @return array{derniersUtilisateurs: \Knp\Component\Pager\Pagination\PaginationInterface, consommations: array<int,int>, geo: array<int,array{pays:?string, ville:?string}>, emptyMessage: string}
     */
    private function donneesClients(): array
    {
        $clients = $this->utilisateurRepository->paginateClients(1);
        $ids = array_map(static fn ($u) => $u->getId(), $clients->getItems());

        $geo = [];
        foreach ($clients->getItems() as $u) {
            $geo[$u->getId()] = $this->localisationDe($this->entrepriseRepresentative($u));
        }

        return [
            'derniersUtilisateurs' => $clients,
            'consommations'        => $this->consumptionRepository->totauxParProprietaires($ids),
            'geo'                  => $geo,
            'emptyMessage'         => 'Aucun client en mode payant pour l\'instant.',
        ];
    }

    #[Route('/dashboard/block/utilisateurs', name: 'console.dashboard.block_utilisateurs', methods: ['GET'])]
    public function blockUtilisateurs(): Response
    {
        return $this->render('console/dashboard/_block_utilisateurs.html.twig', $this->donneesUtilisateurs());
    }

    #[Route('/dashboard/utilisateurs-fragment', name: 'console.dashboard.utilisateurs_fragment', methods: ['GET'])]
    public function utilisateursFragment(): Response
    {
        return $this->render('console/dashboard/_utilisateurs_list.html.twig', $this->donneesUtilisateurs());
    }

    /**
     * Derniers utilisateurs + consommation cumulée de tokens par utilisateur
     * en tant que payeur (un seul agrégat) ; le solde restant est lu sur
     * l'utilisateur lui-même dans le gabarit.
     *
     * @return array{derniersUtilisateurs: \Knp\Component\Pager\Pagination\PaginationInterface, consommations: array<int,int>, geo: array<int,array{pays:?string, ville:?string}>}
     */
    private function donneesUtilisateurs(): array
    {
        $utilisateurs = $this->utilisateurRepository->paginateRegularUsers(1);
        $ids = array_map(static fn ($u) => $u->getId(), $utilisateurs->getItems());

        // Localisation dérivée de l'entreprise représentative de chaque compte
        // (l'utilisateur ne porte pas lui-même de pays/ville).
        $geo = [];
        foreach ($utilisateurs->getItems() as $u) {
            $geo[$u->getId()] = $this->localisationDe($this->entrepriseRepresentative($u));
        }

        return [
            'derniersUtilisateurs' => $utilisateurs,
            'consommations'        => $this->consumptionRepository->totauxParProprietaires($ids),
            'geo'                  => $geo,
        ];
    }

    #[Route('/dashboard/block/coupons', name: 'console.dashboard.block_coupons', methods: ['GET'])]
    public function blockCoupons(): Response
    {
        return $this->render('console/dashboard/_block_coupons.html.twig', $this->donneesCoupons());
    }

    #[Route('/dashboard/coupons-fragment', name: 'console.dashboard.coupons_fragment', methods: ['GET'])]
    public function couponsFragment(): Response
    {
        return $this->render('console/dashboard/_coupons_list.html.twig', $this->donneesCoupons());
    }

    /**
     * Derniers coupons (les plus récents d'abord), paginés. Réutilise la liste
     * paginée du dépôt (tri sur l'id décroissant).
     *
     * @return array{derniersCoupons: \Knp\Component\Pager\Pagination\PaginationInterface}
     */
    private function donneesCoupons(): array
    {
        return ['derniersCoupons' => $this->couponRepository->paginateAll(1)];
    }

    /**
     * Plans tarifaires : liste des paquets prépayés effectifs (config singleton
     * ou repli sur les constantes). Bloc statique, sans pagination ni date.
     */
    #[Route('/dashboard/block/plans', name: 'console.dashboard.block_plans', methods: ['GET'])]
    public function blockPlans(): Response
    {
        return $this->render('console/dashboard/_block_plans.html.twig', [
            'packs' => $this->parametres->packs(),
        ]);
    }

    /**
     * Fiscalité JS Brokers : taxes actives ventilées sur le revenu de l'année
     * civile en cours (même assiette que les KPIs du tableau de bord), avec
     * synthèse TTC / taxes / hors taxe. Bloc statique, sans pagination.
     */
    #[Route('/dashboard/block/taxes', name: 'console.dashboard.block_taxes', methods: ['GET'])]
    public function blockTaxes(): Response
    {
        $annee  = (int) date('Y');
        $revenu = $this->purchaseRepository->totals([
            'from' => sprintf('%d-01-01', $annee),
            'to'   => sprintf('%d-12-31', $annee),
        ])['revenue'];

        return $this->render('console/dashboard/_block_taxes.html.twig', [
            'taxes'          => $this->taxesVente->ventilation($revenu),
            'revenuTotal'    => $revenu,
            'revenuHorsTaxe' => $this->taxesVente->revenuHorsTaxe($revenu),
            'montantTaxes'   => $this->taxesVente->montantTaxes($revenu),
            'annee'          => $annee,
        ]);
    }
}
