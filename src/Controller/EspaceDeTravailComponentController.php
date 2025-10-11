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
use ReflectionClass;
use App\Entity\Monnaie;
use App\Entity\CompteBancaire;
use App\Entity\Taxe;
use App\Entity\TypeRevenu;
use App\Entity\Tranche;
use App\Entity\Chargement;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Bordereau;
use App\Entity\RevenuCourtier;
use App\Entity\Piste;
use App\Entity\Tache;
use App\Entity\Feedback;
use App\Entity\Groupe;
use App\Entity\Client;
use App\Entity\Assureur;
use App\Entity\Contact;
use App\Entity\Risque;
use App\Entity\Avenant;
use App\Entity\Partenaire;
use App\Entity\Cotation;
use App\Entity\ModelePieceSinistre;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Document;
use App\Entity\Classeur;
use App\Entity\Invite;
use App\Entity\RevenuPourCourtier;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository
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
            Monnaie::class => 'App\Controller\Admin\MonnaieController::index',
            CompteBancaire::class => 'App\Controller\Admin\CompteBancaireController::index',
            Taxe::class => 'App\Controller\Admin\TaxeController::index',
            TypeRevenu::class => 'App\Controller\Admin\TypeRevenuController::index',
            Tranche::class => 'App\Controller\Admin\TrancheController::index',
            Chargement::class => 'App\Controller\Admin\ChargementController::index',
            Note::class => 'App\Controller\Admin\NoteController::index',
            Paiement::class => 'App\Controller\Admin\PaiementController::index',
            Bordereau::class => 'App\Controller\Admin\BordereauController::index',
            RevenuPourCourtier::class => 'App\Controller\Admin\RevenuCourtierController::index',
        ],
        // MARKETING
        '_view_manager_marketing.html.twig' => [
            Piste::class => 'App\Controller\Admin\PisteController::index',
            Tache::class => 'App\Controller\Admin\TacheController::index',
            Feedback::class => 'App\Controller\Admin\FeedbackController::index',
        ],
        // PRODUCTION
        '_view_manager_production.html.twig' => [
            Groupe::class => 'App\Controller\Admin\GroupeController::index',
            Client::class => 'App\Controller\Admin\ClientController::index',
            Assureur::class => 'App\Controller\Admin\AssureurController::index',
            Contact::class => 'App\Controller\Admin\ContactController::index',
            Risque::class => 'App\Controller\Admin\RisqueController::index',
            Avenant::class => 'App\Controller\Admin\AvenantController::index',
            Partenaire::class => 'App\Controller\Admin\PartenaireController::index',
            Cotation::class => 'App\Controller\Admin\CotationController::index',
        ],
        // SINISTRE
        '_view_manager_sinistre.html.twig' => [
            ModelePieceSinistre::class => 'App\Controller\Admin\ModelePieceSinistreController::index',
            NotificationSinistre::class => 'App\Controller\Admin\NotificationSinistreController::index',
            OffreIndemnisationSinistre::class => 'App\Controller\Admin\OffreIndemnisationSinistreController::index',
        ],
        // ADMINISTRATION
        '_view_manager_administration.html.twig' => [
            Document::class => 'App\Controller\Admin\DocumentController::index',
            Classeur::class => 'App\Controller\Admin\ClasseurController::index',
            Invite::class => 'App\Controller\Admin\InviteController::index',
        ],
        //PARAMETRES
        '_mon_compte_component.html.twig' => 'App\Controller\RegistrationController::register',
        '_licence_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
    ];




    #[Route(
        '/{idInvite}/{idEntreprise}',
        name: 'index',
        requirements: [
            'idInvite' => Requirement::DIGITS,
            'idEntreprise' => Requirement::DIGITS
        ],
        methods: ['GET','POST']
    )]
    public function index(int $idInvite, int $idEntreprise, Request $request): Response
    {
        // MISSION 2 & 4 : Vérifier si l'entreprise existe.
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        if (!$entreprise) {
            // Si l'entreprise n'est pas trouvée, on lance une exception pour générer une page 404.
            // C'est une mesure de sécurité et de propreté.
            throw $this->createNotFoundException("L'espace de travail pour l'entreprise avec l'ID $idEntreprise n'a pas été trouvé.");
        }
        
        // NOUVEAU : Vérification de l'invité et de son appartenance à l'entreprise
        /** @var Invite|null $invite */
        $invite = $this->inviteRepository->find($idInvite);

        // On vérifie si l'invité existe ET si son entreprise correspond à celle de l'URL.
        // Cette condition unique gère les deux cas : invité inexistant ou invité appartenant à une autre entreprise.
        if (!$invite || $invite->getEntreprise()->getId() !== $entreprise->getId()) {
            throw new AccessDeniedHttpException("Vous n'avez pas les droits pour accéder à cet espace de travail.");
        }

        $menuData = [
            // MISSION 1 : Le code existant pour la création du menu est conservé.
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
                                "entity_name" => Monnaie::class,
                            ],
                            "Comptes bancaires" => [
                                "icone" => "clarity:piggy-bank-solid", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                                "entity_name" => CompteBancaire::class,
                            ],
                            "Taxes" => [
                                "icone" => "emojione-monotone:police-car-light", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                                "entity_name" => Taxe::class,
                            ],
                            "Types Revenus" => [
                                "icone" => "hugeicons:award-01", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                                "entity_name" => TypeRevenu::class,
                            ],
                            "Tranches" => [
                                "icone" => "icon-park-solid:chart-proportion", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                                "entity_name" => Tranche::class,
                            ],
                            "Types Chargements" => [
                                "icone" => "tabler:truck-loading", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                                "entity_name" => Chargement::class,
                            ],
                            "Notes" => [
                                "icone" => "hugeicons:invoice-04", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                                "entity_name" => Note::class,
                            ],
                            "Paiements" => [
                                "icone" => "streamline:payment-10-solid", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                                "entity_name" => Paiement::class,
                            ],
                            "Bordereaux" => [
                                "icone" => "gg:list", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                                "entity_name" => Bordereau::class,
                            ],
                            "Revenus pour courtier" => [
                                "icone" => "hugeicons:money-bag-02", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager.html.twig",
                                "entity_name" => RevenuPourCourtier::class,
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
                                "entity_name" => Piste::class,
                            ],
                            "Tâches" => [
                                "icone" => "mingcute:task-2-fill", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_marketing.html.twig",
                                "entity_name" => Tache::class,
                            ],
                            "Feedbacks" => [
                                "icone" => "fluent-mdl2:feedback", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_marketing.html.twig",
                                "entity_name" => Feedback::class,
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
                                "entity_name" => Groupe::class,
                            ],
                            "Clients" => [
                                "icone" => "fa-solid:house-user", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_production.html.twig",
                                "entity_name" => Client::class,
                            ],
                            "Assureurs" => [
                                "icone" => "wpf:security-checked", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_production.html.twig",
                                "entity_name" => Assureur::class,
                            ],
                            "Contacts" => [
                                "icone" => "hugeicons:contact-01", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_production.html.twig",
                                "entity_name" => Contact::class,
                            ],
                            "Risques" => [
                                "icone" => "ep:goods", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_production.html.twig",
                                "entity_name" => Risque::class,
                            ],
                            "Avenants" => [
                                "icone" => "iconamoon:edit-fill", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_production.html.twig",
                                "entity_name" => Avenant::class,
                            ],
                            "Intermédiaires" => [
                                "icone" => "carbon:partnership", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_production.html.twig",
                                "entity_name" => Partenaire::class,
                            ],
                            "Propositions" => [
                                "icone" => "streamline:store-computer-solid", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_production.html.twig",
                                "entity_name" => Cotation::class,
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
                                "entity_name" => ModelePieceSinistre::class,
                            ],
                            "Notifications" => [
                                "icone" => "emojione-monotone:fire", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_sinistre.html.twig",
                                "entity_name" => NotificationSinistre::class,
                            ],
                            "Règlements" => [
                                "icone" => "icon-park-outline:funds", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_sinistre.html.twig",
                                "entity_name" => OffreIndemnisationSinistre::class,
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
                                "entity_name" => Document::class,
                            ],
                            "Classeurs" => [
                                "icone" => "ic:baseline-folder", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_administration.html.twig",
                                "entity_name" => Classeur::class,
                            ],
                            "Invités" => [
                                "icone" => "raphael:user", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_view_manager_administration.html.twig",
                                "entity_name" => Invite::class,
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

        // CORRECTION : Utilisation d'une fonction récursive qui modifie le tableau par référence via ses clés.
        $processMenu = function (&$array) use (&$processMenu) {
            foreach ($array as $key => &$value) {
                if (is_array($value)) {
                    $processMenu($value); // Appel récursif sur les sous-tableaux
                } elseif ($key === 'entity_name' && is_string($value) && class_exists($value)) {
                    // Transformation du nom de classe complet en nom court
                    $value = (new \ReflectionClass($value))->getShortName();
                }
            }
        };
        $processMenu($menuData); // On lance la transformation sur tout le tableau

        return $this->render('espace_de_travail_component/index.html.twig', [
            'menu_data' => $menuData,
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
    #[Route(
        '/api/load-component/{idInvite}/{idEntreprise}', 
        name: 'api_load_component', 
        requirements: [
            'idInvite' => Requirement::DIGITS,
            'idEntreprise' => Requirement::DIGITS
        ],
        methods: ['GET']
    )]
    public function loadComponent(int $idInvite, int $idEntreprise, Request $request, LoggerInterface $logger): Response
    {
        // La validation de l'accès est maintenant implicitement faite par la route principale 'index'
        // qui a déjà vérifié l'association Invite/Entreprise.
        // On peut ajouter une double vérification ici si on veut être encore plus sûr.
        $invite = $this->inviteRepository->find($idInvite);
        if (!$invite || $invite->getEntreprise()->getId() !== $idEntreprise) {
            throw new AccessDeniedHttpException("Accès non autorisé à ce composant.");
        }

        // LOG: Ce que le serveur reçoit du client
        $logger->info('[ESPACE_DE_TRAVAIL] API /load-component reçue.', [
            'query_params' => $request->query->all()
        ]);

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
        if (is_array($controllerActions)) {
            $found = false;
            // On parcourt les actions possibles pour ce composant
            foreach ($controllerActions as $classFqcn => $action) {
                // On compare le nom court de la clé avec le nom d'entité reçu
                if ((new \ReflectionClass($classFqcn))->getShortName() === $entityName) {
                    $controllerAction = $action;
                    $found = true;
                    $logger->info('[ESPACE_DE_TRAVAIL] Action trouvée pour l\'entité.', ['entity' => $entityName, 'action' => $controllerAction]);
                    break; // On a trouvé, on arrête la boucle
                }
            }
            if (!$found) {
                $logger->error('[ESPACE_DE_TRAVAIL] Action non trouvée pour l\'entité.', ['entity_short_name' => $entityName, 'available_keys' => array_keys($controllerActions)]);
                return new Response('Action de contrôleur non trouvée pour l\'entité: ' . $entityName, Response::HTTP_FORBIDDEN);
            }
        } elseif (is_string($controllerActions)) {
            $controllerAction = $controllerActions;
        } else {
            return new Response('Configuration du contrôleur invalide.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // 3. Exécuter la sous-requête vers le contrôleur dédié et retourner sa réponse
        // C'est ici que la magie opère. Symfony va appeler TableauDeBordController::index()
        // et nous donner son rendu HTML.
        return $this->forward($controllerAction, [
            // Vous pouvez même passer des paramètres au contrôleur cible si nécessaire
            'idEntreprise' => $idEntreprise,
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
