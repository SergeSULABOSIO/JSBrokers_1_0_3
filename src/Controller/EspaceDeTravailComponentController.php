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

use App\Constantes\Constante;
use Twig\Environment;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Route('/espacedetravail', name: 'app_espace_de_travail_component.')]
#[IsGranted('ROLE_USER')]
class EspaceDeTravailComponentController extends AbstractController
{

    public function __construct(
        private EntityManagerInterface $em,
        private Constante $constante,
        private JSBDynamicSearchService $searchService, // Ajoutez cette ligne
    ) {}

    /**
     * @var array<string, string>
     * Table de correspondance entre le nom du composant et l'action du contrôleur à appeler.
     * Format : 'nom_du_composant' => 'Namespace\Controller::methode'
     */
    private const COMPONENT_MAP = [
        '_tableau_de_bord_component.html.twig' => 'App\Controller\Admin\EntrepriseDashbordController::index',
        // FINANCES
        '_view_manager.html.twig' => [
            'monnaies' => 'App\Controller\Admin\MonnaieController::index',
            'comptes_bancaires' => 'App\Controller\Admin\CompteBancaireController::index',
            'taxes' => 'App\Controller\Admin\TaxeController::index',
            'types_revenus' => 'App\Controller\Admin\TypeRevenuController::index',
            'tranches' => 'App\Controller\Admin\TrancheController::index',
            'types_chargements' => 'App\Controller\Admin\ChargementController::index',
            'notes' => 'App\Controller\Admin\NoteController::index',
            'paiements' => 'App\Controller\Admin\PaiementController::index',
            'bordereaux' => 'App\Controller\Admin\BordereauController::index',
            'revenus' => 'App\Controller\Admin\RevenuCourtierController::index',
        ],
        // MARKETING
        '_view_manager_marketing.html.twig' => [
            'pistes' => 'App\Controller\Admin\PisteController::index',
            'taches' => 'App\Controller\Admin\TacheController::index',
            'feedbacks' => 'App\Controller\Admin\FeedbackController::index',
        ],
        // PRODUCTION
        '_view_manager_production.html.twig' => [
            'groupes' => 'App\Controller\Admin\GroupeController::index',
            'clients' => 'App\Controller\Admin\ClientController::index',
            'assureurs' => 'App\Controller\Admin\AssureurController::index',
            'contacts' => 'App\Controller\Admin\ContactController::index',
            'risques' => 'App\Controller\Admin\RisqueController::index',
            'avenants' => 'App\Controller\Admin\AvenantController::index',
            'intermediaires' => 'App\Controller\Admin\PartenaireController::index',
            'propositions' => 'App\Controller\Admin\CotationController::index',
        ],
        // SINISTRE
        '_view_manager_sinistre.html.twig' => [
            'types_pieces_sinistres' => 'App\Controller\Admin\ModelePieceSinistreController::index',
            'notifications_sinistres' => 'App\Controller\Admin\NotificationSinistreController::index',
            'reglements_sinistres' => 'App\Controller\Admin\OffreIndemnisationController::index',
        ],
        // ADMINISTRATION
        '_view_manager_administration.html.twig' => [
            'documents' => 'App\Controller\Admin\DocumentController::index',
            'classeurs' => 'App\Controller\Admin\ClasseurController::index',
            'invites' => 'App\Controller\Admin\InviteController::index',
        ],
        //PARAMETRES
        '_mon_compte_component.html.twig' => 'App\Controller\RegistrationController::register',
        '_licence_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
    ];




    #[Route('/{id}', name: 'index', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Entreprise $entreprise, Request $request): Response
    {
        // [cite_start] Le tableau associatif tel que fourni dans le prompt [cite: 16-183]
        $menuData = [
            'colonne_1' => [
                'tableau_de_bord' => [
                    'icone' => "ant-design:dashboard-twotone", //source: https://ux.symfony.com/icons
                    'nom' => "Tableau de bord",
                    'description' => "Le tableau de bord vous présente la santé de votre société en un seul coup d'oeil.",
                    'composant_twig' => "_tableau_de_bord_component.html.twig",
                ],
                'groupes' => [
                    "Finances" => [   //Groupe Finances
                        'icone' => "mdi:finance", //source: https://ux.symfony.com/icons
                        'description' => "Le groupe Finance présente les fonctions indispensables pour la gestion et le parametrage de tout ce qui a trait avec les finances au sein de votre société de courtage",
                        'rubriques' => [
                            "Monnaies" => [
                                "icone" => "tdesign:money-filled", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                            ],
                            "Comptes bancaires" => [
                                "icone" => "clarity:piggy-bank-solid", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                            ],
                            "Taxes" => [
                                "icone" => "emojione-monotone:police-car-light", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                            ],
                            "Types Revenus" => [
                                "icone" => "hugeicons:award-01", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                            ],
                            "Tranches" => [
                                "icone" => "icon-park-solid:chart-proportion", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                            ],
                            "Types Chargements" => [
                                "icone" => "tabler:truck-loading", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                            ],
                            "Notes" => [
                                "icone" => "hugeicons:invoice-04", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                            ],
                            "Paiements" => [
                                "icone" => "streamline:payment-10-solid", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                            ],
                            "Bordereaux" => [
                                "icone" => "gg:list", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                            ],
                            "Revenus" => [
                                "icone" => "hugeicons:money-bag-02", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                            ],
                        ],
                    ],
                    "Marketing" => [   //Groupe Marketing
                        "icone" => "hugeicons:marketing", //source: https://ux.symfony.com/icons
                        "description" => "Le groupe Marketing présente les fonctions indispensables pour la gestion et le parametrage de tout ce qui a trait avec vos interactions avec les clients mais aussi entre vous en termes des tâches et des feedbacks.",
                        "rubriques" => [
                            "Pistes" => [
                                "icone" => "fa-solid:road", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_marketing.html.twig",
                            ],
                            "Tâches" => [
                                "icone" => "mingcute:task-2-fill", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_marketing.html.twig",
                            ],
                            "Feedbacks" => [
                                "icone" => "fluent-mdl2:feedback", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_marketing.html.twig",
                            ],
                        ],
                    ],
                    "Production" => [  //Groupe Production
                        "icone" => "streamline-flex:production-belt-time", //source: https://ux.symfony.com/icons
                        "description" => "Le groupe Production présente les fonctions indispensables pour la gestion et le parametrage de tout ce qui a trait avec votre production càd vos activités génératrices des revenus.",
                        "rubriques" => [
                            "Groupes" => [
                                "icone" => "clarity:group-solid", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_production.html.twig",
                            ],
                            "Clients" => [
                                "icone" => "fa-solid:house-user", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_production.html.twig",
                            ],
                            "Assureurs" => [
                                "icone" => "wpf:security-checked", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_production.html.twig",
                            ],
                            "Contacts" => [
                                "icone" => "hugeicons:contact-01", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_production.html.twig",
                            ],
                            "Risques" => [
                                "icone" => "ep:goods", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_production.html.twig",
                            ],
                            "Avenants" => [
                                "icone" => "iconamoon:edit-fill", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_production.html.twig",
                            ],
                            "Intermédiaires" => [
                                "icone" => "carbon:partnership", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_production.html.twig",
                            ],
                            "Propositions" => [
                                "icone" => "streamline:store-computer-solid", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_production.html.twig",
                            ],
                        ],
                    ],
                    "Sinistre" => [   //Groupe Sinistre
                        "icone" => "game-icons:mine-explosion", //source: https://ux.symfony.com/icons
                        "description" => "Le groupe Sinistre présente les fonctions indispensables pour la gestion et le parametrage de tout ce qui a trait avec les sinistres et leurs compensations.",
                        "rubriques" => [
                            "Types pièces" => [
                                "icone" => "codex:file", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_sinistre.html.twig",
                            ],
                            "Notifications" => [
                                "icone" => "emojione-monotone:fire", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_sinistre.html.twig",
                            ],
                            "Règlements" => [
                                "icone" => "icon-park-outline:funds", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_sinistre.html.twig",
                            ],
                        ],
                    ],
                    "Administration" => [   //Groupe Administration
                        "icone" => "clarity:administrator-solid", //source: https://ux.symfony.com/icons
                        "description" => "Le groupe Administration présente les fonctions indispensables pour la gestion et le parametrage de tout ce qui a trait avec l'organisation des documents chargés sur votre compte ainsi que les personnes que vous inviterez pour travailler en équipe.",
                        "rubriques" => [
                            "Documents" => [
                                "icone" => "famicons:document", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_administration.html.twig",
                            ],
                            "Classeurs" => [
                                "icone" => "ic:baseline-folder", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_administration.html.twig",
                            ],
                            "Invités" => [
                                "icone" => "raphael:user", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_administration.html.twig",
                            ],
                        ],
                    ],
                ],
                "parametres" => [
                    "Mon Compte" => [
                        "icone" => "material-symbols:settings", //source: https://ux.symfony.com/icons
                        "composant_twig" => "_mon_compte_component.html.twig",
                        "description" => "Ici la description sur la fonction Mon Compte",
                    ],
                    "Licence" => [
                        "icone" => "ci:arrows-reload-01", //source: https://ux.symfony.com/icons
                        "composant_twig" => "_licence_component.html.twig",
                        "description" => "Ici la description de la fonction de paiement de la licence / Version Premium ou Payante.",
                    ],
                ],
            ],
            "colonne_2" => [
                "logo" => "images/entreprises/logofav.png", //source: dans le dossier "public/images/entreprises/logofav.png"
                "titre" => "JS Brokers",
                "description" => "Votre partenaire fiable pour optimiser la gestion de votre porte-feuille client.",
                "version" => "1.1.0",
            ],
        ];

        // Remplir le reste des données pour être complet...
        // ... (Ajoutez ici toutes les données des groupes Production, Marketing, etc.)
        return $this->render('espace_de_travail_component/index.html.twig', [
            'menu_data' => $menuData,
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
    #[Route('/api/load-component', name: 'app_load_component', methods: ['GET'])]
    // public function loadComponent(Request $request, Environment $twig, LoggerInterface $logger): Response
    public function loadComponent(Request $request): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        $componentName = $request->query->get('component');
        $entityName = $request->query->get('entity'); // Nouveau paramètre

        if (!$componentName) {
            return new Response('Nom de composant manquant.', Response::HTTP_BAD_REQUEST);
        }

        // 1. Sécurité : Vérifier que le composant demandé est bien dans notre liste autorisée
        if (!isset(self::COMPONENT_MAP[$componentName])) {
            return new Response('Composant non autorisé ou non trouvé.', Response::HTTP_FORBIDDEN);
        }

        $controllerActions = self::COMPONENT_MAP[$componentName];

        // Si les actions sont dans un sous-tableau (basé sur l'entité), on le sélectionne
        if (is_array($controllerActions) && isset($controllerActions[$entityName])) {
            $controllerAction = $controllerActions[$entityName];
        } elseif (is_string($controllerActions)) {
            $controllerAction = $controllerActions;
        } else {
            return new Response('Action de contrôleur non trouvée pour cette entité.', Response::HTTP_FORBIDDEN);
        }

        // 3. Exécuter la sous-requête vers le contrôleur dédié et retourner sa réponse
        // C'est ici que la magie opère. Symfony va appeler TableauDeBordController::index()
        // et nous donner son rendu HTML.
        return $this->forward($controllerAction, [
            // Vous pouvez même passer des paramètres au contrôleur cible si nécessaire
            'idEntreprise' => $utilisateur->getConnectedTo()->getId(),
        ]);
    }

    #[Route('/api/get-entity-details/{entityType}/{id}', name: 'api_get_entity_details')]
    public function getEntityDetails(
        string $entityType,
        int $id,
        EntityManagerInterface $em,
        Constante $constante
    ): JsonResponse {
        // Sécurité : Vérifier si l'entité est autorisée
        if (!in_array($entityType, $this->searchService::$allowedEntities)) {
            throw $this->createAccessDeniedException("Cette entité n'est pas accessible.");
        }

        $entityClass = 'App\\Entity\\' . $entityType;
        $entity = $em->getRepository($entityClass)->find($id);

        if (!$entity) {
            throw new NotFoundHttpException("L'entité '$entityType' avec l'ID '$id' n'a pas été trouvée.");
        }

        $entityCanvas = $constante->getEntityCanvas($entityClass);
        $responseData = [
            'entity' => $entity,
            'entityType' => $entityType,
            'entityCanvas' => $entityCanvas
        ];
        $this->loadCalculatedValues($entityCanvas, $entity, $constante);

        // On retourne à la fois l'entité et son canvas
        return $this->json($responseData, 200, [], ['groups' => 'list:read']); // Important d'utiliser le groupe de sérialisation
    }

    public function loadCalculatedValues($entityCanvas, $entity, Constante $constante)
    {
        // --- MODIFICATION : AJOUT DES VALEURS CALCULÉES ---
        foreach ($entityCanvas['liste'] as $field) {
            if ($field['type'] === 'Calcul') {
                $functionName = $field['fonction'];
                $args = []; // Initialiser le tableau d'arguments

                // On vérifie si la clé "params" existe et n'est pas vide
                if (!empty($field['params'])) {
                    // CAS 1 : Des paramètres spécifiques sont listés (logique existante)
                    $paramNames = $field['params'];
                    $args = array_map(function ($paramName) use ($entity) {
                        $getter = 'get' . ucfirst($paramName);
                        if (method_exists($entity, $getter)) {
                            return $entity->$getter();
                        }
                        return null;
                    }, $paramNames);
                } else {
                    // CAS 2 : La clé "params" est absente, on passe l'entité entière
                    $args[] = $entity;
                }

                // On appelle la fonction du service avec les arguments préparés
                if (method_exists($constante, $functionName)) {
                    $calculatedValue = $constante->$functionName(...$args);
                    // On ajoute le résultat à l'objet entité pour la sérialisation
                    $entity->{$field['code']} = $calculatedValue;
                }
            }
        }
        // --- FIN DE LA MODIFICATION ---
    }

    #[Route('/api/get-entities/{entityType}/{idEntreprise}', name: 'api_get_entities', methods: ['GET'])]
    public function getEntities(string $entityType, int $idEntreprise, EntityManagerInterface $em): JsonResponse
    {
        // Sécurité : Vérifier si l'entité est autorisée
        if (!in_array($entityType, JSBDynamicSearchService::$allowedEntities)) {
            throw $this->createAccessDeniedException("Cette entité n'est pas accessible.");
        }

        $entityClass = 'App\\Entity\\' . $entityType;
        $repository = $em->getRepository($entityClass);
        
        // On récupère toutes les entités. Pour de grandes listes, il faudrait paginer.
        $entities = $repository->findAll();

        // On retourne les entités en utilisant le groupe de sérialisation
        return $this->json($entities, 200, [], ['groups' => 'list:read']);
    }
}
