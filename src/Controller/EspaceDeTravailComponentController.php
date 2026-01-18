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
        // La validation de l'accès est maintenant dans une méthode privée dédiée.
        $this->validateWorkspaceAccess($request);

        // La logique de transformation du menu est maintenant dans le ControllerUtilsTrait.
        $processedMenuData = $this->processDataForShortEntityNames($this->menuData);

        return $this->render('espace_de_travail_component/index.html.twig', [
            'menu_data' => $processedMenuData,
            'idEntreprise' => $idEntreprise,
            'idInvite' => $idInvite,
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
        // La validation est maintenant centralisée dans une méthode privée.
        $this->validateWorkspaceAccess($request);

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
}
