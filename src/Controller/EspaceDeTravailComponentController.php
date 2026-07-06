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
use App\Constantes\Constante;
use App\Entity\Client;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Symfony\Component\PropertyAccess\PropertyAccess;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        private array $menuData // Injection du paramètre de service
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

        return $this->render('espace_de_travail_component/index.html.twig', [
            'menu_data' => $processedMenuData,
            'idEntreprise' => $idEntreprise,
            'idInvite' => $idInvite,
            'entreprise' => $access['entreprise'],
            'entrepriseNom' => $access['entreprise']->getNom(),
            'isEntrepriseAdmin' => $isEntrepriseAdmin,
            'welcome' => $welcome,
            'hasPerimetre' => $hasPerimetre,
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
