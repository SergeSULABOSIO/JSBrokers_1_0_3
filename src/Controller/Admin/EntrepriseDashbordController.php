<?php

namespace App\Controller\Admin;

use DateTimeImmutable;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Repository\EntrepriseRepository;
use App\Service\Onboarding\OnboardingCompletude;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\DashboardDataProvider;
use App\Services\ExercicesDuCabinet;
use App\Services\ServiceMonnaies;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\Workspace\AppartenanceAuCabinet;
use App\Service\Workspace\CabinetActif;

#[Route("/admin/entreprise_dashbord", name: 'admin.entreprise_dashboard.')]
#[IsGranted('ROLE_USER')]
class EntrepriseDashbordController extends AbstractController
{

    public function __construct(
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private WorkspaceAccessResolver $accessResolver,
        private OnboardingCompletude $onboardingCompletude,
        private CabinetActif $cabinetActif,
        private AppartenanceAuCabinet $appartenance,
    ) {
    }

    /**
     * Le cabinet que la route désigne — à condition que ce soit le cabinet OUVERT.
     *
     * Chaque bloc et chaque fragment lisait `{idEntreprise}` dans l'URL puis faisait
     * `find()` : la garde de bloc ne juge que le TYPE (« peut-il lire les Notes ? »), avec
     * l'invité de la session. Changer ce nombre suffisait donc à afficher les KPIs, la
     * comptabilité, les renouvellements ou les encaissements de n'importe quel cabinet.
     * Tous les blocs font cette comparaison.
     */
    private function entrepriseDeLaRoute(int $idEntreprise): Entreprise
    {
        return $this->appartenance->exigerLIdentifiantDuCabinet($idEntreprise);
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


    // L'ANCIEN TABLEAU DE BORD (action `index`, page autonome à formulaire de dates) a été
    // retiré le 2026-10-14 : aucun lien ne le servait plus, il plantait (500), et l'espace de
    // travail le remplace — blocs ci-dessous. Sa route répond désormais 404.

    #[Route('/workspace/{idEntreprise}', name: 'workspace', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function loadWorkspaceComponent(int $idEntreprise, ExercicesDuCabinet $exercices): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);

        // LA DETTE DE CONFIGURATION EN TÊTE DU TABLEAU DE BORD.
        //
        // C'est l'écran d'arrivée : si le rappel doit se voir quelque part, c'est ici. Il
        // ne concerne que le PROPRIÉTAIRE — configurer le cabinet n'est pas l'affaire d'un
        // invité, et le lui rappeler serait lui demander ce qu'il ne peut pas faire.
        //
        // Mode `scoreSeul` : aucun aperçu de l'existant n'est construit, donc aucun
        // surcoût sur un écran ouvert à chaque entrée dans l'espace de travail.
        $estProprietaire = $entreprise !== null && $entreprise->getUtilisateur() === $user;

        // ── L'EXERCICE COMPTABLE, ET POURQUOI CE N'EST PAS L'ANNÉE COURANTE ──────────
        //
        // ⚠ UN TABLEAU DE BORD QUI AFFICHE ZÉRO SANS DIRE POURQUOI FAIT CROIRE À UNE PANNE.
        // L'exercice était figé sur `date('Y')` : un cabinet qui venait de reprendre son
        // historique ouvrait son écran d'arrivée sur une année vide et lisait 0,00 partout
        // — ses données existaient, elles étaient justes, et rien ne le lui disait.
        //
        // On ouvre donc sur le dernier exercice qui porte quelque chose, exactement comme
        // l'écran des Documents comptables le fait déjà. Les autres années sont à un clic,
        // et le choix de l'utilisateur prime au rechargement (voir le contrôleur Stimulus).
        $exercicesOfferts = $entreprise === null ? [] : $exercices->disponibles($entreprise);
        $exerciceRetenu = $entreprise === null ? (int) date('Y') : $exercices->defaut($entreprise);

        return $this->render('components/_tableau_de_bord_component.html.twig', [
            'utilisateur' => $user,
            'entreprise'  => $entreprise,
            'exercices'   => $exercicesOfferts,
            'exercice'    => $exerciceRetenu,
            'onboardingBilan' => $estProprietaire ? $this->onboardingCompletude->scoreSeul($entreprise) : null,
            'onboardingEtapesCitees' => $estProprietaire ? $this->onboardingCompletude->etapesACiter($entreprise) : [],
        ]);
    }

    /**
     * Bloc « Mes congés » : le bandeau des trois soldes de l'agent connecté, et ses
     * demandes en cours.
     *
     * ── C'EST LE TABLEAU DE BORD DE L'AGENT ─────────────────────────────────────────
     * La spécification décrit un écran dédié ; il n'en fallait pas un. Le solde se lit
     * là où le collaborateur regarde déjà en arrivant, et l'historique de ses demandes
     * est la rubrique « Congés » elle-même, déjà filtrée sur lui.
     *
     * ── AUCUN COÛT EN TOKENS N'EST AFFICHÉ ──────────────────────────────────────────
     * Le demandeur n'est pas le payeur, et lui montrer un prix ne peut que le dissuader
     * de poser des jours auxquels il a droit.
     *
     * Gate : lecture des Congés. L'agent y a droit d'office (DroitCongeParDefaut), le
     * propriétaire par son bypass.
     */
    #[Route('/block/conges/{idEntreprise}', name: 'block_conges', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockConges(
        int $idEntreprise,
        \App\Service\Conge\CalculateurSolde $calculateurSolde,
        \App\Services\Canvas\CalculationProvider $calculationProvider,
        \App\Repository\DemandeCongeRepository $demandeRepository,
    ): Response {
        // Ce bloc lit l'invité CONNECTÉ, pas la route ; il exige pourtant, comme ses
        // voisins, que la route désigne le cabinet ouvert : une règle sans exception.
        $this->entrepriseDeLaRoute($idEntreprise);
        if ($denied = $this->denyBlockIfCannotRead('DemandeConge')) { return $denied; }

        $user = $this->getUser();
        $invite = $user instanceof Utilisateur ? $this->accessResolver->resolveConnectedInvite($user) : null;
        if ($invite === null) {
            return new Response('');
        }

        $solde = $calculateurSolde->pour($invite);

        // Les demandes NON CLOSES : celles qui attendent une décision et celles qui sont
        // approuvées mais pas encore passées. Une demande refusée l'an dernier n'a rien
        // à faire dans un bandeau qui répond à « où en suis-je ? ».
        $demandes = $demandeRepository->pourAgent($invite, [
            \App\Entity\DemandeConge::STATUT_SOUMISE,
            \App\Entity\DemandeConge::STATUT_APPROUVEE,
        ], 5);

        // Les libellés viennent de la MÊME stratégie d'indicateurs que la liste : période,
        // type et statut se lisent ici du mot exact qu'ils portent là-bas.
        foreach ($demandes as $demande) {
            foreach ($calculationProvider->getIndicateursSpecifics($demande) as $code => $valeur) {
                $demande->{$code} = $valeur;
            }
        }

        return $this->render('components/dashboard/_block_conges.html.twig', [
            'solde' => $solde,
            'demandes' => $demandes,
            'cartes' => [
                [
                    'label' => 'Acquis',
                    'valeur' => $solde->acquis,
                    'detail' => $solde->dontReport > 0
                        ? sprintf('dont %s j de report', number_format($solde->dontReport, 1, ',', ' '))
                        : null,
                    'accent' => false,
                    'aide' => "Vos droits de l'exercice : dotation, report de l'an dernier et ajustements.",
                ],
                [
                    'label' => 'Consommé',
                    'valeur' => $solde->consomme,
                    'detail' => null,
                    'accent' => false,
                    'aide' => 'Jours de vos congés approuvés, passés comme à venir.',
                ],
                [
                    'label' => 'Engagé',
                    'valeur' => $solde->engage,
                    'detail' => null,
                    'accent' => false,
                    'aide' => "Jours de vos demandes soumises, en attente de décision. Ils sont déjà retenus sur votre disponible.",
                ],
                [
                    'label' => 'Disponible',
                    'valeur' => $solde->disponible(),
                    'detail' => null,
                    'accent' => true,
                    'aide' => "Ce que vous pouvez encore poser aujourd'hui, engagements déduits.",
                ],
            ],
        ]);
    }

    #[Route('/block/kpis/{idEntreprise}', name: 'block_kpis', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockKpis(
        int $idEntreprise,
        DashboardDataProvider $provider,
        ServiceMonnaies $serviceMonnaies,
        Request $request,
        ExercicesDuCabinet $exercices,
    ): Response {
        if ($denied = $this->denyBlockIfCannotRead('Note')) { return $denied; }
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);
        $exercice = $exercices->retenir($entreprise, $request->query->getInt('exercice') ?: null);
        $debut = new DateTimeImmutable("1/1/" . $exercice . " 00:00");
        $fin   = new DateTimeImmutable("12/31/" . $exercice . " 23:59");

        return $this->render('components/dashboard/_block_kpis.html.twig', [
            'entreprise'       => $entreprise,
            'exercice'         => $exercice,
            'paiementsTotaux'  => $provider->getPaiementsTotaux($entreprise, $debut, $fin),
            // Le portefeuille EN VIGUEUR aujourd'hui (nombre de polices) ; la PRODUCTION de
            // l'exercice retenu (primes, commissions, rétro, taxes, détail des revenus).
            'policiesActives'  => $provider->getPoliciesActives($entreprise),
            'primesTotales'    => $provider->getPrimesTotales($entreprise, $exercice),
            'retrocommissions' => $provider->getRetrocommissionsTotales($entreprise, $exercice),
            'taxes'            => $provider->getTaxesTotales($entreprise, $exercice),
            'commissions'      => $provider->getCommissionsTotales($entreprise, $exercice),
            'revenusBreakdown' => $provider->getRevenusPercusBreakdown($entreprise, $exercice),
            // Ce que le cabinet a encaissé DEPUIS TOUJOURS : c'est ce qui permet au bandeau
            // de distinguer « rien encaissé » de « rien encaissé sur CET exercice ».
            'encaisseTousExercices' => $provider->getTotalEncaisseCommissions($entreprise),
            'deviseCode'       => $serviceMonnaies->getCodeMonnaieAffichage(),
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
        Request $request,
        ExercicesDuCabinet $exercices,
    ): Response {
        if ($denied = $this->denyBlockIfCannotRead('DepenseCourtier')) { return $denied; }
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);

        // ⚠ « MÊME SOURCE QUE LES DOCUMENTS COMPTABLES » DEVIENT ENFIN VRAI. La source
        // l'était déjà, mais pas l'exercice : ce bloc forçait `date('Y')` là où l'écran des
        // Documents comptables ouvre sur le dernier exercice pourvu. Les deux écrans
        // affichaient donc des chiffres différents, sous une phrase qui promettait
        // l'identité.
        $exercice = $exercices->retenir($entreprise, $request->query->getInt('exercice') ?: null);

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
            // KPIs comptables prêts à afficher : chacun porte son infobulle enrichie
            // (paragraphe 1 = ce que l'indicateur représente pour le courtier ;
            // paragraphe 2 = comment il est calculé), rendue par l'infobulle unique
            // (data-infobulle, chantier 3).
            'kpis'              => $this->buildComptaKpis($documents),
            'depensesPayees'    => $depensesPayees,
            'depensesEngagees'  => $depensesEngagees,
            'fournisseursActifs' => $fournisseurRepository->count(['entreprise' => $entreprise, 'actif' => true]),
            'deviseCode'        => $serviceMonnaies->getCodeMonnaieAffichage(),
        ]);
    }

    /**
     * Construit les cinq KPIs comptables affichés dans le bloc « Comptabilité »,
     * chacun avec sa valeur et son infobulle explicative en deux paragraphes :
     *  - `intro`  : ce que l'indicateur représente pour le courtier ;
     *  - `calcul` : comment il est calculé.
     * L'infobulle est l'infobulle unique (data-infobulle, chantier 3), déclarée par
     * _block_compta.html.twig.
     *
     * @param array $documents Structure de DocumentsComptablesBuilder (resultat + tft).
     *
     * @return array<int, array{label:string, valeur:float, intro:string, calcul:string}>
     */
    private function buildComptaKpis(array $documents): array
    {
        $resultat = $documents['resultat'];
        $tft      = $documents['tft'];

        return [
            [
                'label'  => 'Commissions encaissées',
                'valeur' => $resultat['totalProduits'],
                'intro'  => "Le chiffre d'affaires de votre cabinet : le total des commissions de courtage réellement encaissées sur l'exercice, exprimé hors taxes.",
                'calcul' => "Somme des produits (compte 706), c'est-à-dire la part hors taxe des paiements reçus sur vos notes de débit (factures de commission), diminuée des éventuels avoirs.",
            ],
            [
                'label'  => 'Charges',
                'valeur' => $resultat['totalCharges'],
                'intro'  => "Ce que votre activité vous coûte sur l'exercice : rétro-commissions versées aux intermédiaires, taxes à votre charge et dépenses de fonctionnement du cabinet.",
                'calcul' => "Somme des comptes de charges (classe 6) — rémunérations d'intermédiaires (632), impôts et taxes du courtier (641) et dépenses (montant hors taxe déductible).",
            ],
            [
                'label'  => 'Résultat net',
                'valeur' => $resultat['resultat'],
                'intro'  => "Le bénéfice (ou la perte) de votre cabinet sur l'exercice : ce qu'il vous reste une fois toutes les charges déduites de vos commissions.",
                'calcul' => "Commissions encaissées − Charges. Un montant négatif, affiché en rouge, signale une perte.",
            ],
            [
                'label'  => 'Décaissements',
                'valeur' => $tft['decaissements'],
                'intro'  => "L'argent réellement sorti de votre trésorerie sur l'exercice : paiements effectués aux intermédiaires, aux autorités fiscales et à vos fournisseurs.",
                'calcul' => "Somme des sorties de trésorerie (crédits des comptes Banque 521 et Caisse 571), hors apport en capital.",
            ],
            [
                // ⚠ « CUMUL » N'EST PAS DÉCORATIF. Les quatre indicateurs qui précèdent sont
                // des FLUX de l'exercice, celui-ci est un STOCK depuis l'origine. Un
                // « Résultat net : 0,00 » à côté d'une « Trésorerie : 100 030 740,96 » est
                // juste dans les deux cas, et parfaitement incompréhensible tant que rien
                // ne dit lequel est lequel. Le report d'ouverture est donc nommé, avec son
                // montant : c'est lui qui explique tout l'écart.
                'label'  => 'Trésorerie (cumul)',
                'valeur' => $tft['cloture'],
                'intro'  => sprintf(
                    "Ce dont votre cabinet dispose en banque et en caisse à la clôture — depuis son ORIGINE, "
                    . "et non sur le seul exercice. Il en reportait %s à l'ouverture.",
                    number_format((float) ($tft['ouverture'] ?? 0), 2, ',', ' '),
                ),
                'calcul' => "Trésorerie d'ouverture + encaissements − décaissements + apports en capital (solde de clôture des comptes 521 et 571).",
            ],
        ];
    }

    #[Route('/block/renewals/{idEntreprise}', name: 'block_renewals', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockRenewals(int $idEntreprise, DashboardDataProvider $provider, ServiceMonnaies $serviceMonnaies): Response
    {
        if ($denied = $this->denyBlockIfCannotRead('Avenant')) { return $denied; }
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);

        return $this->render('components/dashboard/_renewals.html.twig', [
            'entreprise'      => $entreprise,
            'renouvellements' => $provider->getAllRenouvellements($entreprise),
            // Collection SÉPARÉE, et non fusionnée : les non renouvelables ne réclament aucune
            // action et ne doivent pas gonfler les seaux d'urgence. Elles forment leur propre
            // onglet, comme leur propre chip dans la rubrique.
            'nonRenouvelables' => $provider->getAvenantsNonRenouvelables($entreprise),
            'deviseCode'      => $serviceMonnaies->getCodeMonnaieAffichage(),
        ]);
    }

    #[Route('/renewals-fragment/{idEntreprise}', name: 'renewals_fragment', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadRenewalsFragment(int $idEntreprise, DashboardDataProvider $provider, ServiceMonnaies $serviceMonnaies): Response
    {
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);

        return $this->render('components/dashboard/_renewals_list.html.twig', [
            'entreprise'      => $entreprise,
            'renouvellements' => $provider->getAllRenouvellements($entreprise),
            'nonRenouvelables' => $provider->getAvenantsNonRenouvelables($entreprise),
            'deviseCode'      => $serviceMonnaies->getCodeMonnaieAffichage(),
        ]);
    }

    #[Route('/block/encaissements/{idEntreprise}', name: 'block_encaissements', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockEncaissements(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        if ($denied = $this->denyBlockIfCannotRead('Paiement')) { return $denied; }
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);

        return $this->render('components/dashboard/_encaissements.html.twig', [
            'entreprise'           => $entreprise,
            'derniersEncaissements' => $provider->getDerniersEncaissements($entreprise),
        ]);
    }

    #[Route('/encaissements-fragment/{idEntreprise}', name: 'encaissements_fragment', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadEncaissementsFragment(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);

        return $this->render('components/dashboard/_encaissements_list.html.twig', [
            'derniersEncaissements' => $provider->getDerniersEncaissements($entreprise),
        ]);
    }

    #[Route('/block/depenses/{idEntreprise}', name: 'block_depenses', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockDepenses(int $idEntreprise, DashboardDataProvider $provider, ServiceMonnaies $serviceMonnaies): Response
    {
        if ($denied = $this->denyBlockIfCannotRead('DepenseCourtier')) { return $denied; }
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);

        return $this->render('components/dashboard/_depenses.html.twig', [
            'utilisateur'      => $this->getUser(),
            'entreprise'       => $entreprise,
            'dernieresDepenses' => $provider->getDernieresDepenses($entreprise),
            'deviseCode'       => $serviceMonnaies->getCodeMonnaieAffichage(),
        ]);
    }

    #[Route('/depenses-fragment/{idEntreprise}', name: 'depenses_fragment', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadDepensesFragment(int $idEntreprise, DashboardDataProvider $provider, ServiceMonnaies $serviceMonnaies): Response
    {
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);

        return $this->render('components/dashboard/_depenses_list.html.twig', [
            'utilisateur'      => $this->getUser(),
            'entreprise'       => $entreprise,
            'dernieresDepenses' => $provider->getDernieresDepenses($entreprise),
            'deviseCode'       => $serviceMonnaies->getCodeMonnaieAffichage(),
        ]);
    }

    #[Route('/block/sinistres/{idEntreprise}', name: 'block_sinistres', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockSinistres(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        if ($denied = $this->denyBlockIfCannotRead('NotificationSinistre')) { return $denied; }
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);

        return $this->render('components/dashboard/_sinistres.html.twig', [
            'entreprise'        => $entreprise,
            'derniersSinistres' => $provider->getDerniersSinistres($entreprise),
        ]);
    }

    #[Route('/sinistres-fragment/{idEntreprise}', name: 'sinistres_fragment', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadSinistresFragment(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);

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
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);

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
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);

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
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);

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
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);

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
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);

        return $this->render('components/dashboard/_sidebar.html.twig', [
            'entreprise' => $entreprise,
        ]);
    }

    #[Route('/feedbacks-fragment/{idEntreprise}', name: 'feedbacks_fragment', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadFeedbacksFragment(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);
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
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);
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
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);
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
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);
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
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);
        return $this->render('components/dashboard/_block_notes.html.twig', [
            'utilisateur' => $user,
            'entreprise'  => $entreprise,
            'notes'       => $provider->getDerniersNotes($entreprise),
            'deviseCode'  => $serviceMonnaies->getCodeMonnaieAffichage(),
        ]);
    }

    #[Route('/notes-fragment/{idEntreprise}', name: 'notes_fragment', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadNotesFragment(int $idEntreprise, DashboardDataProvider $provider, ServiceMonnaies $serviceMonnaies): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);
        return $this->render('components/dashboard/_notes_list.html.twig', [
            'utilisateur' => $user,
            'entreprise'  => $entreprise,
            'notes'       => $provider->getDerniersNotes($entreprise),
            'deviseCode'  => $serviceMonnaies->getCodeMonnaieAffichage(),
        ]);
    }

    #[Route('/bordereaux-fragment/{idEntreprise}', name: 'bordereaux_fragment', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBordereauFragment(int $idEntreprise, DashboardDataProvider $provider): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);
        return $this->render('components/dashboard/_bordereaux_list.html.twig', [
            'utilisateur' => $user,
            'entreprise'  => $entreprise,
            'bordereaux'  => $provider->getDerniersBordereaux($entreprise),
        ]);
    }

    #[Route('/block/production/{idEntreprise}', name: 'block_production', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadBlockProduction(
        int $idEntreprise,
        DashboardDataProvider $provider,
        ServiceMonnaies $serviceMonnaies,
        Request $request,
        ExercicesDuCabinet $exercices,
    ): Response {
        if ($denied = $this->denyBlockIfCannotRead('Avenant')) { return $denied; }
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);
        $exercice = $exercices->retenir($entreprise, $request->query->getInt('exercice') ?: null);
        $monthly = $provider->getProductionMensuelle($entreprise, $exercice);

        // ⚠ LES TROIS URL EMPORTENT L'EXERCICE. Le graphique les rappelle lui-même pour se
        // rafraîchir ou changer de mode : sans le paramètre, il repartirait sur l'année
        // courante au premier clic, et l'écran se contredirait sous les yeux.
        $avecExercice = ['idEntreprise' => $idEntreprise, 'exercice' => $exercice];

        return $this->render('components/dashboard/_block_production.html.twig', [
            'entreprise'   => $entreprise,
            'monthly'      => array_values($monthly),
            'year'         => $exercice,
            'deviseCode'   => $serviceMonnaies->getCodeMonnaieAffichage(),
            'prodDataUrl'  => $this->generateUrl('admin.entreprise_dashboard.production_data',   $avecExercice),
            'tableDataUrl' => $this->generateUrl('admin.entreprise_dashboard.production_table',  $avecExercice),
            'groupUrl'     => $this->generateUrl('admin.entreprise_dashboard.production_group',  $avecExercice),
        ]);
    }

    #[Route('/production-data/{idEntreprise}', name: 'production_data', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadProductionData(
        int $idEntreprise,
        DashboardDataProvider $provider,
        ServiceMonnaies $serviceMonnaies,
        Request $request,
        ExercicesDuCabinet $exercices,
    ): JsonResponse {
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);
        $exercice = $exercices->retenir($entreprise, $request->query->getInt('exercice') ?: null);
        $monthly = $provider->getProductionMensuelle($entreprise, $exercice);

        return new JsonResponse([
            'monthly'  => array_values($monthly),
            'year'     => $exercice,
            'currency' => $serviceMonnaies->getCodeMonnaieAffichage(),
            'total'    => array_sum($monthly),
        ]);
    }

    #[Route('/production-table/{idEntreprise}', name: 'production_table', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function loadProductionTableData(
        int $idEntreprise,
        DashboardDataProvider $provider,
        ServiceMonnaies $serviceMonnaies,
        Request $request,
        ExercicesDuCabinet $exercices,
    ): JsonResponse {
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);
        $exercice = $exercices->retenir($entreprise, $request->query->getInt('exercice') ?: null);
        $data = $provider->getProductionTableData($entreprise, $exercice);
        $data['currency'] = $serviceMonnaies->getCodeMonnaieAffichage();
        return new JsonResponse($data);
    }

    #[Route('/production-group/{idEntreprise}', name: 'production_group', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function productionGroup(
        int $idEntreprise,
        DashboardDataProvider $provider,
        ServiceMonnaies $serviceMonnaies,
        Request $request,
        ExercicesDuCabinet $exercices,
    ): JsonResponse {
        $entreprise = $this->entrepriseDeLaRoute($idEntreprise);
        $exercice = $exercices->retenir($entreprise, $request->query->getInt('exercice') ?: null);
        $data = $provider->getProductionGroupData($entreprise, $exercice);
        $data['currency'] = $serviceMonnaies->getCodeMonnaieAffichage();
        return new JsonResponse($data);
    }
}
