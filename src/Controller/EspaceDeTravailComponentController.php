<?php

namespace App\Controller;

use Twig\Environment;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route('/espacedetravail', name: 'app_espace_de_travail_component.')]
#[IsGranted('ROLE_USER')]
class EspaceDeTravailComponentController extends AbstractController
{

    /**
     * @var array<string, string>
     * Table de correspondance entre le nom du composant et l'action du contrôleur à appeler.
     * Format : 'nom_du_composant' => 'Namespace\Controller::methode'
     */
    private const COMPONENT_MAP = [
        '_tableau_de_bord_component.html.twig' => 'App\Controller\Admin\EntrepriseDashbordController::index',
        '_monnaies_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_comptes_bancaires_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_taxes_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_tranches_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_types_chargements_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_notes_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_paiements_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_bordereaux_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_revenus_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_pistes_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_taches_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_feedbacks_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_groupes_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_clients_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_assureurs_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_contacts_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_risques_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_avenants_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_intermediaires_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_propositions_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_types_pieces_sinistres_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_notifications_sinistres_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_reglements_sinistres_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_documents_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_classeurs_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_invites_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_mon_compte_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_licence_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',
        '_types_revenus_component.html.twig' => 'App\Controller\Admin\NotificationSinistreController::index',

        // '_tableau_de_bord_component.html.twig' => 'App\Controller\TableauDeBordController::index',
        // '_monnaies_component.html.twig'        => 'App\Controller\MonnaiesController::index',
        // '_comptes_bancaires_component.html.twig' => 'App\Controller\ComptesBancairesController::index',
        // ... Ajoutez ici toutes les correspondances pour vos composants
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
                                "composant_twig" => "_monnaies_component.html.twig",
                            ],
                            "Comptes bancaires" => [
                                "icone" => "clarity:piggy-bank-solid", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_comptes_bancaires_component.html.twig",
                            ],
                            "Taxes" => [
                                "icone" => "emojione-monotone:police-car-light", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_taxes_component.html.twig",
                            ],
                            "Types Revenus" => [
                                "icone" => "hugeicons:award-01", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_types_revenus_component.html.twig",
                            ],
                            "Tranches" => [
                                "icone" => "icon-park-solid:chart-proportion", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_tranches_component.html.twig",
                            ],
                            "Types Chargements" => [
                                "icone" => "tabler:truck-loading", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_types_chargements_component.html.twig",
                            ],
                            "Notes" => [
                                "icone" => "hugeicons:invoice-04", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_notes_component.html.twig",
                            ],
                            "Paiements" => [
                                "icone" => "streamline:payment-10-solid", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_paiements_component.html.twig",
                            ],
                            "Bordereaux" => [
                                "icone" => "gg:list", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_bordereaux_component.html.twig",
                            ],
                            "Revenus" => [
                                "icone" => "hugeicons:money-bag-02", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_revenus_component.html.twig",
                            ],
                        ],
                    ],
                    "Marketing" => [   //Groupe Marketing
                        "icone" => "hugeicons:marketing", //source: https://ux.symfony.com/icons
                        "description" => "Le groupe Marketing présente les fonctions indispensables pour la gestion et le parametrage de tout ce qui a trait avec vos interactions avec les clients mais aussi entre vous en termes des tâches et des feedbacks.",
                        "rubriques" => [
                            "Pistes" => [
                                "icone" => "fa-solid:road", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_pistes_component.html.twig",
                            ],
                            "Tâches" => [
                                "icone" => "mingcute:task-2-fill", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_taches_component.html.twig",
                            ],
                            "Feedbacks" => [
                                "icone" => "fluent-mdl2:feedback", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_feedbacks_component.html.twig",
                            ],
                        ],
                    ],
                    "Production" => [  //Groupe Production
                        "icone" => "streamline-flex:production-belt-time", //source: https://ux.symfony.com/icons
                        "description" => "Le groupe Production présente les fonctions indispensables pour la gestion et le parametrage de tout ce qui a trait avec votre production càd vos activités génératrices des revenus.",
                        "rubriques" => [
                            "Groupes" => [
                                "icone" => "clarity:group-solid", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_groupes_component.html.twig",
                            ],
                            "Clients" => [
                                "icone" => "fa-solid:house-user", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_clients_component.html.twig",
                            ],
                            "Assureurs" => [
                                "icone" => "wpf:security-checked", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_assureurs_component.html.twig",
                            ],
                            "Contacts" => [
                                "icone" => "hugeicons:contact-01", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_contacts_component.html.twig",
                            ],
                            "Risques" => [
                                "icone" => "ep:goods", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_risques_component.html.twig",
                            ],
                            "Avenants" => [
                                "icone" => "iconamoon:edit-fill", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_avenants_component.html.twig",
                            ],
                            "Intermédiaires" => [
                                "icone" => "carbon:partnership", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_intermediaires_component.html.twig",
                            ],
                            "Propositions" => [
                                "icone" => "streamline:store-computer-solid", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_propositions_component.html.twig",
                            ],
                        ],
                    ],
                    "Sinistre" => [   //Groupe Sinistre
                        "icone" => "game-icons:mine-explosion", //source: https://ux.symfony.com/icons
                        "description" => "Le groupe Sinistre présente les fonctions indispensables pour la gestion et le parametrage de tout ce qui a trait avec les sinistres et leurs compensations.",
                        "rubriques" => [
                            "Types pièces" => [
                                "icone" => "codex:file", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_types_pieces_sinistres_component.html.twig",
                            ],
                            "Notifications" => [
                                "icone" => "emojione-monotone:fire", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_notifications_sinistres_component.html.twig",
                            ],
                            "Règlements" => [
                                "icone" => "icon-park-outline:funds", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_reglements_sinistres_component.html.twig",
                            ],
                        ],
                    ],
                    "Administration" => [   //Groupe Administration
                        "icone" => "clarity:administrator-solid", //source: https://ux.symfony.com/icons
                        "description" => "Le groupe Administration présente les fonctions indispensables pour la gestion et le parametrage de tout ce qui a trait avec l'organisation des documents chargés sur votre compte ainsi que les personnes que vous inviterez pour travailler en équipe.",
                        "rubriques" => [
                            "Documents" => [
                                "icone" => "famicons:document", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_documents_component.html.twig",
                            ],
                            "Classeurs" => [
                                "icone" => "ic:baseline-folder", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_classeurs_component.html.twig",
                            ],
                            "Invités" => [
                                "icone" => "raphael:user", //source: https://ux.symfony.com/icons
                                "composant_twig" => "_invites_component.html.twig",
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

        // On récupère le nom du composant depuis la requête AJAX
        $componentKey = $request->query->get('component');

        // 1. Sécurité : Vérifier que le composant demandé est bien dans notre liste autorisée
        if (!$componentKey || !isset(self::COMPONENT_MAP[$componentKey])) {
            return new Response('Composant non autorisé ou non trouvé.', Response::HTTP_FORBIDDEN);
        }

        // 2. Récupérer l'action du contrôleur correspondant
        $controllerAction = self::COMPONENT_MAP[$componentKey];

        // 3. Exécuter la sous-requête vers le contrôleur dédié et retourner sa réponse
        // C'est ici que la magie opère. Symfony va appeler TableauDeBordController::index()
        // et nous donner son rendu HTML.
        return $this->forward($controllerAction, [
            // Vous pouvez même passer des paramètres au contrôleur cible si nécessaire
            'idEntreprise' => $utilisateur->getConnectedTo()->getId(),
        ]);
    }
}
