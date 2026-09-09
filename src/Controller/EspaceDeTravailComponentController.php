<?php

/**
 * @file Ce fichier contient le contrôleur EspaceDeTravailComponentController.
 * @description Ce contrôleur agit comme une "tour de contrôle" pour l'espace de travail principal.
 * Il est responsable de :
 * 1. Définir la structure du menu interactif (`$menuData`).
 * 2. Maintenir une table de correspondance (`COMPONENT_MAP`) qui associe un nom de composant Twig
 *    (ex: `_view_manager.html.twig`) à l'action du contrôleur PHP qui doit le générer
 *    (ex: `App\Controller\Admin\ClientController::index`).
 * 3. Fournir des points de terminaison API pour charger dynamiquement ces composants et obtenir des détails sur les entités.
 */

namespace App\Controller;

use Twig\Environment;
use Psr\Log\LoggerInterface;
use App\Ai\Acces\PorteDeKet;
use App\Constantes\Constante;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Symfony\Component\PropertyAccess\PropertyAccess;
use App\Repository\AssistantConversationRepository;
use App\Repository\AssistantParametresRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Onboarding\OnboardingCompletude;
use App\Service\Terminal\DetecteurDeTerminal;
use App\Service\Terminal\TerminalContext;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route('/espacedetravail', name: 'app_espace_de_travail_component.')]
#[IsGranted('ROLE_USER')]
class EspaceDeTravailComponentController extends AbstractController
{

    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private array $menuData, // Injection du paramètre de service
        private OnboardingCompletude $onboardingCompletude,
        // LE TERMINAL DÉCIDE DE LA SURFACE. Téléphone et tablette reçoivent la
        // conversation avec Ket en plein écran ; l'ordinateur garde les quatre
        // colonnes. Cf. App\Service\Terminal\Terminal::modeKet().
        private TerminalContext $terminal,
        private PorteDeKet $porteDeKet,
        private AssistantConversationRepository $conversationRepository,
        private AssistantParametresRepository $parametresRepository,
    ) {}

    protected function getCollectionMap(): array
    {
        // Utilise la méthode du trait pour construire dynamiquement la carte.
        return [];
    }

    protected function getParentAssociationMap(): array
    {
        return [];
    }

    #[Route('/{idInvite}/{idEntreprise}',
        name: 'index',
        requirements: ['idInvite' => Requirement::DIGITS,'idEntreprise' => Requirement::DIGITS],
        methods: ['GET','POST']
    )]
    public function index(int $idInvite, int $idEntreprise, Request $request): Response
    {
        // AMÉLIORATION : On passe explicitement les IDs de la route pour une validation sécurisée.
        $access = $this->validateWorkspaceAccess($idEntreprise, $idInvite);

        // On synchronise l'entreprise « connectée » avec l'espace de travail courant.
        // Les services et formulaires d'autocomplétion (ex: GroupeAutocompleteField) filtrent
        // par `getConnectedTo()` : sans cette synchronisation, l'endpoint d'autocomplétion
        // (route UX autonome, sans contexte de workspace) utiliserait une entreprise obsolète
        // et ne renverrait aucune suggestion. On n'écrit en base que si la valeur change.
        /** @var Utilisateur $user */
        $user = $this->getUser();
        if ($user->getConnectedTo() !== $access['entreprise']) {
            $user->setConnectedTo($access['entreprise']);
            $this->em->flush();
        }

        // ── LE TERMINAL CHOISIT LA SURFACE ──────────────────────────────────────
        //
        // Sur téléphone et tablette, on ne travaille pas dans quatre colonnes : on
        // travaille EN PARLANT à Ket. La bascule est ici, et non dans le gabarit,
        // parce que tout ce qui suit — traitement du menu, filtrage par périmètre,
        // bilan de démarrage — ne sert qu'aux colonnes. Le calculer pour ne pas
        // l'afficher serait payer plein tarif un rendu qu'on jette.
        //
        // MÊME ROUTE, MÊME URL, MÊMES GARDES : la validation d'accès et la synchro
        // de `connectedTo` ci-dessus ont déjà eu lieu. Un lien vers l'espace de
        // travail reste donc valide d'un appareil à l'autre, et aucune surface de
        // sécurité n'est ajoutée — seul le gabarit final change.
        if ($this->terminal->modeKet()) {
            return $this->renderEspaceKet($access['entreprise'], $access['invite']);
        }

        // La logique de transformation du menu est maintenant dans le ControllerUtilsTrait.
        $processedMenuData = $this->processDataForShortEntityNames($this->menuData);

        // Adaptation au périmètre de l'invité connecté : on retire du menu les rubriques
        // hors de ses droits de lecture (le propriétaire garde le menu complet). Source
        // unique partagée avec le blocage serveur et le Twig (DRY).
        $processedMenuData = $this->workspaceAccessResolver->filterMenu($processedMenuData, $access['invite']);

        // Fail-closed : un invité sans aucun périmètre ne voit qu'une coquille d'accueil
        // l'invitant à contacter le propriétaire, plutôt qu'un espace de travail vide.
        $hasPerimetre = $this->workspaceAccessResolver->hasAnyPerimetre($access['invite']);

        // L'accès à l'édition de l'entreprise (groupe « Paramètres ») est réservé au
        // propriétaire du compte — même critère que denyUnlessOwner() côté EntrepriseController.
        $isEntrepriseAdmin = $access['entreprise']->getUtilisateur() === $this->getUser();

        // État d'accueil : on affiche le panneau de bienvenue (étapes suggérées) soit
        // à la sortie de l'onboarding (?welcome=1), soit tant que l'espace ne contient
        // encore aucun client (espace fraîchement amorcé). Comptage bon marché.
        $welcome = $request->query->getBoolean('welcome')
            || $this->em->getRepository(Client::class)->count(['entreprise' => $access['entreprise']]) === 0;

        // LA DETTE DE CONFIGURATION, rappelée en permanence au propriétaire.
        //
        // Mode `scoreSeul` et pas `pour` : ce bilan est calculé À CHAQUE ouverture de
        // l'espace de travail. Les aperçus de l'existant coûtent une requête par étape et
        // ne servent qu'au guide lui-même, ouvert à la demande.
        //
        // Rien n'est calculé pour un invité : configurer le cabinet est l'affaire de son
        // propriétaire, et le voyant ne s'affiche que pour lui.
        $onboardingBilan = null;
        $onboardingEtapesCitees = [];
        if ($isEntrepriseAdmin) {
            $onboardingBilan = $this->onboardingCompletude->scoreSeul($access['entreprise']);
            $onboardingEtapesCitees = $this->onboardingCompletude->etapesACiter($access['entreprise']);
        }

        return $this->render('espace_de_travail_component/index.html.twig', [
            'menu_data' => $processedMenuData,
            'idEntreprise' => $idEntreprise,
            'idInvite' => $idInvite,
            'entreprise' => $access['entreprise'],
            'entrepriseNom' => $access['entreprise']->getNom(),
            'isEntrepriseAdmin' => $isEntrepriseAdmin,
            'welcome' => $welcome,
            'hasPerimetre' => $hasPerimetre,
            'onboardingBilan' => $onboardingBilan,
            'onboardingEtapesCitees' => $onboardingEtapesCitees,
            // Le lien du courriel de synthèse porte `?onboarding=1` : le voyant s'ouvre
            // alors de lui-même sur le guide, plutôt que de rendre une seconde fois les
            // mêmes cartes ailleurs dans la page.
            'onboardingAutoOuvrir' => $request->query->getBoolean('onboarding'),
        ]);
    }

    /**
     * L'ESPACE DE TRAVAIL EN MODE KET : la conversation, en plein écran, et rien
     * d'autre.
     *
     * ── LA PORTE D'ABORD ────────────────────────────────────────────────────
     * Ket est verrouillée par deux conditions (module dans le périmètre de
     * l'invité, solde de tokens payant du cabinet). Sur ordinateur, un compte qui
     * n'y a pas droit garde tout le reste de l'application : le refus se lit dans
     * une seule rubrique. Sur téléphone, il n'y a rien d'autre — un refus muet
     * donnerait un écran vide sans explication. On sert donc une page qui NOMME la
     * raison et, pour le propriétaire seul, la façon d'y remédier.
     *
     * ── QUELLE CONVERSATION S'OUVRE ─────────────────────────────────────────
     * La plus récente (`findPourInvite` trie déjà par `updatedAt` décroissant) :
     * on reprend là où l'on s'était arrêté, ce qui est le geste attendu en
     * ambulatoire. Aucune n'existe encore ? On n'en crée PAS ici : cette action
     * répond à un GET, et un GET n'écrit pas. Le gabarit ouvre alors la feuille
     * des conversations, dont le bouton « Nouvelle conversation » fait le POST —
     * le même chemin exactement que sur ordinateur.
     */
    private function renderEspaceKet(Entreprise $entreprise, ?Invite $invite): Response
    {
        // LA SORTIE QUI MARCHE TOUJOURS, calculée ici et non dans le gabarit : elle
        // a besoin de l'identifiant de l'invité, qu'un gabarit devrait sinon
        // inventer. `?terminal=ordinateur` est lu par le détecteur PHP puis
        // mémorisé en cookie (TerminalCookieSubscriber) — le choix survit donc à
        // la navigation suivante.
        $versionOrdinateurUrl = $this->generateUrl('app_espace_de_travail_component.index', [
            'idInvite' => $invite?->getId() ?? 0,
            'idEntreprise' => $entreprise->getId(),
            DetecteurDeTerminal::PARAM => 'ordinateur',
        ]);

        $motif = $this->porteDeKet->motifDeFermeture($invite, $entreprise);
        if ($motif !== null) {
            return $this->render('espace_de_travail_component/ket_indisponible.html.twig', [
                'motif' => $motif,
                'assistantNom' => $this->parametresRepository->nomPour($entreprise),
                'entrepriseNom' => (string) $entreprise->getNom(),
                'estProprietaire' => $this->porteDeKet->estProprietaire($invite, $entreprise),
                'versionOrdinateurUrl' => $versionOrdinateurUrl,
                'sortieUrl' => $this->generateUrl('admin.entreprise.index'),
            ]);
        }

        /** @var Invite $invite La porte ouverte garantit un invité non nul. */
        $conversations = $this->conversationRepository->findPourInvite($invite, $entreprise);
        $derniere = $conversations[0] ?? null;

        return $this->render('espace_de_travail_component/ket.html.twig', [
            'idEntreprise' => $entreprise->getId(),
            'idInvite' => $invite->getId(),
            'entrepriseNom' => (string) $entreprise->getNom(),
            'assistantNom' => $this->parametresRepository->nomPour($entreprise),
            // LE CHAT N'EST PAS RENDU ICI, IL EST ADRESSÉ.
            //
            // Son partial a besoin de bien plus que d'une conversation : programme du
            // jour, fiches de contexte, thème de l'utilisateur, plafonds de fichiers.
            // Tout cela est déjà calculé par `admin.assistantia.chat`, qui re-vérifie
            // au passage les deux verrous. Recopier ce calcul ici en ferait une
            // seconde version à tenir à jour — celle qui, un jour, oublierait le
            // programme du jour.
            //
            // Le front va donc chercher le chat à cette URL, exactement comme le
            // workspace de bureau le fait au rechargement
            // (`_restoreHtmlVisualizationTab`). Le squelette et la barre de
            // progression couvrent l'aller-retour.
            'chatUrl' => $derniere === null ? null : $this->generateUrl('admin.assistantia.chat', [
                'idEntreprise' => $entreprise->getId(),
                'idConversation' => $derniere->getId(),
            ]),
            'conversationsUrl' => $this->generateUrl('admin.assistantia.workspace', [
                'idEntreprise' => $entreprise->getId(),
            ]),
            'versionOrdinateurUrl' => $versionOrdinateurUrl,
            'sortieUrl' => $this->generateUrl('admin.entreprise.index'),
        ]);
    }


    /**
     * Charge et rend un composant Twig demandé via AJAX.
     *
     * @param Request $request
     * @param Environment $twig
     * @param LoggerInterface $logger
     * @return Response
     */
    #[Route('/api/load-component/{idInvite}/{idEntreprise}', 
        name: 'api_load_component', 
        requirements: ['idInvite' => Requirement::DIGITS,'idEntreprise' => Requirement::DIGITS],
        methods: ['GET']
    )]
    public function loadComponent(int $idInvite, int $idEntreprise, Request $request, LoggerInterface $logger): Response
    {
        // AMÉLIORATION : On passe explicitement les IDs de la route pour une validation sécurisée.
        $this->validateWorkspaceAccess($idEntreprise, $idInvite);

        $logger->info('[ESPACE_DE_TRAVAIL] API /load-component reçue, redirection vers le contrôleur compétent.', [
            'params' => $request->query->all()
        ]);
        return $this->forwardToComponent($request);
    }


    #[Route('/api/get-entity-details/{entityType}/{id}', name: 'api_get_entity_details')]
    public function getEntityDetails(string $entityType, int $id): JsonResponse {
        // La logique de récupération et de préparation des données est maintenant dans le trait.
        $responseData = $this->getEntityDetailsForType($entityType, $id);
        // Le contrôleur reste responsable de la réponse JSON finale.
        return $this->json($responseData, 200, [], ['groups' => 'list:read']);
    }
    

    #[Route('/api/get-entities/{entityType}/{idEntreprise}', name: 'api_get_entities', methods: ['GET'])]
    public function getEntities(string $entityType, int $idEntreprise): JsonResponse
    {
        // La logique de validation et de récupération est maintenant dans le trait.
        // On retourne les entités en utilisant le groupe de sérialisation
        return $this->json($this->getEntitiesForType($entityType), 200, [], ['groups' => 'list:read']);
    }

    /**
     * Endpoint générique d'autocomplétion pour les critères de type « relation » de la
     * recherche avancée du workspace. Renvoie les entités de l'entité cible correspondant
     * à la saisie, filtrées par l'entreprise du workspace courant (via le service de
     * recherche, qui applique le scope entreprise + getConnectedTo).
     *
     * Format de réponse compatible Tom Select : { results: [{ value, text }], next_page }.
     */
    #[Route('/api/search-autocomplete/{idInvite}/{idEntreprise}',
        name: 'api_search_autocomplete',
        requirements: ['idInvite' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS],
        methods: ['GET']
    )]
    public function searchAutocomplete(int $idInvite, int $idEntreprise, Request $request): JsonResponse
    {
        $access = $this->validateWorkspaceAccess($idEntreprise, $idInvite);

        // Synchronise l'entreprise « connectée » (voir index()) pour que le scope de
        // recherche et l'autocomplétion reflètent bien le workspace courant.
        /** @var Utilisateur $user */
        $user = $this->getUser();
        if ($user->getConnectedTo() !== $access['entreprise']) {
            $user->setConnectedTo($access['entreprise']);
            $this->em->flush();
        }

        $empty = new JsonResponse(['results' => [], 'next_page' => null]);

        $entityType = (string) $request->query->get('entity', '');
        $displayField = (string) $request->query->get('displayField', 'nom');
        // Tom Select envoie « query » ; on accepte « q » en repli.
        $query = trim((string) ($request->query->get('query') ?? $request->query->get('q', '')));

        // Sécurité : entité dans la liste blanche + périmètre de lecture de l'invité.
        if (!in_array($entityType, JSBDynamicSearchService::$allowedEntities, true)) {
            return $empty;
        }
        $entityClass = 'App\\Entity\\' . $entityType;
        if (!$this->mayAccessEntity($entityClass, Invite::ACCESS_LECTURE)) {
            return $empty;
        }

        // Sécurité : le champ d'affichage doit être un identifiant simple (anti-injection DQL).
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $displayField)) {
            $displayField = 'nom';
        }

        // On délègue au service de recherche : LIKE sur le displayField, scope entreprise appliqué.
        $criteria = $query !== ''
            ? [$displayField => ['operator' => 'LIKE', 'value' => $query, 'targetField' => $displayField]]
            : [];

        try {
            $result = $this->searchService->search($entityClass, $criteria, $access['entreprise'], null, 1, 20);
        } catch (\Throwable $e) {
            return $empty;
        }

        $accessor = PropertyAccess::createPropertyAccessor();
        $results = [];
        foreach ($result['data'] as $entity) {
            $label = null;
            try {
                $label = $accessor->getValue($entity, $displayField);
            } catch (\Throwable) {
                // displayField absent sur cette entité : on retombe sur __toString / id.
            }
            if ($label === null || $label === '') {
                $label = method_exists($entity, '__toString') ? (string) $entity : ('#' . $entity->getId());
            }
            $results[] = ['value' => $entity->getId(), 'text' => (string) $label];
        }

        return new JsonResponse(['results' => $results, 'next_page' => null]);
    }
}
