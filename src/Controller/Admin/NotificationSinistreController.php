<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use DateTimeImmutable;
use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Services\ServiceMonnaies;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use App\Form\NotificationSinistreType;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\NotificationSinistreRepository;
use Doctrine\ORM\Mapping\MappingException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/notificationsinistre", name: 'admin.notificationsinistre.')]
#[IsGranted('ROLE_USER')]
class NotificationSinistreController extends AbstractController
{
    public MenuActivator $activator;
    /**
     * @var string[] Liste des entités autorisées pour la recherche.
     * Ajoutez ici toutes les entités que vous souhaitez rendre consultables.
     * Ceci est une mesure de sécurité pour empêcher l'interrogation de n'importe quelle table.
     */
    private array $allowedEntities = [
        'NotificationSinistre',
        // 'User',
        // 'Product',
    ];

    /**
     * @var string[] Liste des opérateurs de comparaison autorisés.
     * Ceci est une mesure de sécurité pour empêcher l'injection de code dans la requête.
     */
    private array $allowedOperators = ['=', '!=', '<', '<=', '>', '>=', 'LIKE'];


    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private NotificationSinistreRepository $notificationSinistreRepository,
        private Constante $constante,
        private ServiceMonnaies $serviceMonnaies,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_CLAIMS);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        return $this->render('admin/notificationsinistre/index.html.twig', [
            'pageName' => $this->translator->trans("notificationsinistre_page_name_new"),
            'utilisateur' => $utilisateur,
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'notificationsinistres' => $this->notificationSinistreRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'activator' => $this->activator,
            'numericAttributes' => $this->constante->getNumericAttributes(new NotificationSinistre()),
        ]);
    }


    #[Route('/create/{idEntreprise}', name: 'create')]
    public function create($idEntreprise, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var NotificationSinistre $notificationsinistre */
        $notificationsinistre = new NotificationSinistre();
        //Paramètres par défaut
        $notificationsinistre->setOccuredAt(new DateTimeImmutable("now"));
        $notificationsinistre->setNotifiedAt(new DateTimeImmutable("now"));
        $notificationsinistre->setInvite($invite);

        $form = $this->createForm(NotificationSinistreType::class, $notificationsinistre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($notificationsinistre);
            $this->em->flush();

            return $this->getJsonData($notificationsinistre);
        }
        return $this->render('admin/notificationsinistre/create.html.twig', [
            'pageName' => $this->translator->trans("notificationsinistre_page_name_new"),
            'utilisateur' => $user,
            'notificationsinistre' => $notificationsinistre,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idNotificationsinistre}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idNotificationsinistre, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var NotificationSinistre $notificationsinistre */
        $notificationsinistre = $this->notificationSinistreRepository->find($idNotificationsinistre);

        $form = $this->createForm(NotificationSinistreType::class, $notificationsinistre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($notificationsinistre); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->em->flush();

            //Le serveur renvoie un objet JSON
            return $this->getJsonData($notificationsinistre);
        }
        //On se dirie vers la page le formulaire d'édition
        return $this->render('admin/notificationsinistre/edit.html.twig', [
            'pageName' => $this->translator->trans("notificationsinistre_page_name_update", [
                ":notificationsinistre" => $notificationsinistre->getDescriptionDeFait(),
            ]),
            'utilisateur' => $user,
            'notificationsinistre' => $notificationsinistre,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/formulaire/{idEntreprise}/{idNotificationsinistre}', name: 'formulaire')]
    public function formulaire($idEntreprise, $idNotificationsinistre, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        //Paramètres par défaut
        /** @var NotificationSinistre $notificationsinistre */
        $notificationsinistre = new NotificationSinistre();
        if ($idNotificationsinistre != -1) {
            $notificationsinistre = $this->notificationSinistreRepository->find($idNotificationsinistre);
        } else {
            $notificationsinistre->setCreatedAt(new DateTimeImmutable("now"));
            $notificationsinistre->setUpdatedAt(new DateTimeImmutable("now"));
            $notificationsinistre->setNotifiedAt(new DateTimeImmutable("now"));
            $notificationsinistre->setOccuredAt(new DateTimeImmutable("now"));
            $notificationsinistre->setInvite($invite);
        }
        // dd($notificationsinistre, $idEntreprise, $idNotificationsinistre);

        $form = $this->createForm(NotificationSinistreType::class, $notificationsinistre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // dd("Ici");

            $avenants = $this->constante->Entreprise_getAvenantsByReference($notificationsinistre->getReferencePolice());
            if (count($avenants) != 0) {
                /** @var Avenant $ave */
                $ave = $avenants[0];
                if ($ave->getCotation() != null) {
                    $notificationsinistre->setAssureur($ave->getCotation()->getAssureur());
                    $notificationsinistre->setAssure($ave->getCotation()->getPiste()->getClient());
                    $notificationsinistre->setRisque($ave->getCotation()->getPiste()->getRisque());
                }
            }

            $this->em->persist($notificationsinistre); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->em->flush();
            //Le serveur renvoie un objet JSON
            return $this->getJsonData($notificationsinistre);
        }
        //On se dirie vers la page le formulaire d'édition
        return $this->render('admin/notificationsinistre/form.html.twig', [
            'utilisateur' => $user,
            'notificationsinistre' => $notificationsinistre,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    private function getJsonData(?NotificationSinistre $notification)
    {
        return $this->json(json_encode([
            "reponse" => "Ok",
            "idNotificationSinistre" => $notification->getId(),
            "referencePolice" => $notification->getReferencePolice(),
            "nbOffres" => count($notification->getOffreIndemnisationSinistres()),
            "nbContacts" => count($notification->getContacts()),
            "nbTaches" => count($notification->getTaches()),
            "nbDocuments" => count($notification->getPieces()),
        ]));
    }

    #[Route('/remove/{idEntreprise}/{idNotificationsinistre}', name: 'remove', requirements: ['idNotificationsinistre' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS])]
    public function remove($idEntreprise, $idNotificationsinistre, Request $request)
    {
        /** @var NotificationSinistre $notificationsinistre */
        $notificationsinistre = $this->notificationSinistreRepository->find($idNotificationsinistre);

        $this->em->remove($notificationsinistre);
        $this->em->flush();

        return $this->json(json_encode([
            "reponse" => "Ok",
        ]));
    }

    #[Route('/remove_many/{idEntreprise}/{tabIDString}', name: 'remove_many', requirements: ['idEntreprise' => Requirement::DIGITS])]
    public function remove_many($idEntreprise, $tabIDString, Request $request)
    {
        try {
            $deletedIDs = [];
            if (null === $tabIDString || empty($tabIDString)) {
                return $this->json(json_encode([
                    "reponse" => "Ok",
                    "message" => "Aucun tableau n'a été envoyé au serveur",
                    "deletedIds" => $deletedIDs,
                ]));
            }
            $idsTab = explode(',', $tabIDString); //On explose cette chaine en tableau.
            $idsTab = array_filter($idsTab, 'is_numeric'); //On prends que les cellules dont les valeurs sont numérique
            $idsTab = array_map('intval', $idsTab); //On prends les value entière uniquement.

            foreach ($idsTab as $id) {
                /** @var NotificationSinistre $notification */
                $notification = $this->notificationSinistreRepository->find($id);
                if ($notification != null) {
                    $this->em->remove($notification);
                    $this->em->flush();
                }
                $notification = $this->notificationSinistreRepository->find($id);
                if ($notification == null) {
                    $deletedIDs[] = $id;
                }
            }
            return $this->json(json_encode([
                "reponse" => "Ok",
                "message" => count($deletedIDs) . " éléments ont été supprimés.",
                "deletedIds" => $deletedIDs,
            ]));
        } catch (\Throwable $th) {
            return $this->json(json_encode([
                "reponse" => "Erreur",
                "message" => $th->getMessage(),
                "deletedIds" => $deletedIDs,
            ]));
        }
    }

    #[Route('/getlistelementdetails/{idEntreprise}/{idNotificationsinistre}', name: 'getlistelementdetails')]
    public function getlistelementdetails($idEntreprise, $idNotificationsinistre, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var NotificationSinistre $notificationsinistre */
        $notificationsinistre = $this->notificationSinistreRepository->find($idNotificationsinistre);

        //On se dirie vers la page le formulaire d'édition
        return $this->render('admin/notificationsinistre/elementview.html.twig', [
            'notificationsinistre' => $notificationsinistre,
            'entreprise' => $entreprise,
            'utilisateur' => $user,
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
        ]);
    }



    #[Route('/sales-demo', name: 'app_sales_demo')]
    public function salesDemo(): Response
    {
        // 1. Définir les attributs numériques et leurs libellés ici
        $numericAttributes = [
            "amount-invoiced" => "Montant Facturé",
            'amount-paid' => "Montant Payé",
            "balance" => "Solde Restant",
        ];

        // Génération de 10 ventes de démonstration
        $sales = [];
        $clients = ['TechCorp', 'Innovate SARL', 'Digital Solutions', 'Global Systems'];
        $items = ['Laptop Pro X', 'Écran 4K Ultra', 'Souris Gamer RGB', 'Clavier Mécanique'];

        for ($i = 1; in_array($i, range(1, 10)); $i++) {
            $invoiced = rand(500, 2000) * 100; // en centimes
            $paid = rand(0, $invoiced);
            $balance = $invoiced - $paid;

            // 2. Créer le nouvel attribut `numericValues` pour chaque vente
            $numericValues = [
                'amount-invoiced' => $invoiced,
                'amount-paid' => $paid,
                'balance' => $balance,
            ];

            $sales[] = [
                'id' => 'sale-' . $i,
                'clientName' => $clients[array_rand($clients)],
                'saleDate' => (new \DateTime())->modify('-' . rand(1, 30) . ' days'),
                'itemName' => $items[array_rand($items)],
                'amountInvoiced' => $invoiced,
                'amountPaid' => $paid,
                'balance' => $invoiced - $paid,
                'numericValues' => $numericValues, // Ajout du tableau associatif
            ];
        }

        return $this->render('pages/sales_demo.html.twig', [
            'sales' => $sales,
            'numericAttributes' => $numericAttributes,
        ]);
    }


    #[Route('/reload/{idEntreprise}', name: 'reload', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function reload($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        return $this->render('admin/notificationsinistre/donnees.html.twig', [
            'pageName' => $this->translator->trans("notificationsinistre_page_name_new"),
            'utilisateur' => $utilisateur,
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'notificationsinistres' => $this->notificationSinistreRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'activator' => $this->activator,
        ]);
    }


    #[Route('/api/dynamic-query/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['POST'])]
    public function query($idEntreprise, Request $request)
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        // Initialisation du statut et des données.
        $status = [
            "error" => null,
            "code" => null,
        ];
        $dataResultat = []; // Les résultats de la requête Doctrine

        // 1. Récupérer et décoder les données de la requête POST
        $data = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $status = [
                "error" => 'Format JSON invalide.',
                "code" => 400,
            ];
            // Puisque nous rendons un template, nous continuons l'exécution pour que le template puisse afficher l'erreur.
        }

        $entityName = $data['entityName'] ?? null;
        $criteria = $data['criteria'] ?? null;

        // Pagination
        $page = (int) ($data['page'] ?? 1); // Page par défaut: 1
        $limit = (int) ($data['limit'] ?? 10); // Limite par défaut: 10 éléments par page

        // Assurez-vous que page et limit sont des valeurs raisonnables
        $page = max(1, $page);
        $limit = max(1, min(100, $limit)); // Limite max de 100 pour éviter les abus de requêtes

        // 2. Valider les données d'entrée
        // Nous vérifions si $status n'a pas déjà été défini par une erreur précédente (JSON invalide)
        if ($status['error'] === null && (!$entityName || !$criteria || !is_array($criteria))) {
            $status = [
                "error" => 'Paramètres manquants ou invalides : entityName et criteria sont requis.',
                "code" => 400,
            ];
        }

        // 3. Vérifier si l'entité est autorisée
        if ($status['error'] === null && !in_array($entityName, $this->allowedEntities, true)) {
            $status = [
                "error" => "L'interrogation de l'entité '{$entityName}' n'est pas autorisée.",
                "code" => 403,
            ];
        }

        // Exécuter la logique de requête seulement si aucune erreur de validation initiale n'est présente
        if ($status['error'] === null) {
            try {
                // Obtenir le repository de l'entité demandée
                $repository = $this->em->getRepository('App\\Entity\\' . $entityName);
                $qb = $repository->createQueryBuilder('e'); // 'e' est l'alias de notre entité principale

                // Tableau pour stocker les alias des entités déjà jointes
                // Cela évite de joindre la même entité plusieurs fois si elle apparaît dans plusieurs critères
                $joinedEntities = [];

                $parameterIndex = 0; // Pour garantir des noms de paramètres uniques

                foreach ($criteria as $field => $value) {
                    $parameterName = 'param' . $parameterIndex++;

                    // --- DÉBUT DE LA LOGIQUE DE GESTION DES RELATIONS (CAS A) ---
                    // Sépare "relation.field" en ["relation", "field"] si un point est présent
                    $fieldParts = explode('.', $field);
                    // Alias de l'entité que nous interrogeons (commence par l'entité principale 'e')
                    $currentAlias = 'e';
                    // Le nom du champ réel à utiliser dans la clause WHERE (par défaut, c'est le champ tel quel)
                    $actualField = $field;

                    // Si le champ contient un point, c'est une relation (ex: "risque.nom")
                    if (count($fieldParts) > 1) {
                        $relationName = $fieldParts[0]; // ex: "risque"
                        $actualField = $fieldParts[1]; // ex: "nom"

                        // Vérifie si cette relation n'a pas déjà été jointe
                        if (!isset($joinedEntities[$relationName])) {
                            // Génère un alias unique pour la nouvelle entité jointe. 
                            // substr($relationName, 0, 1) prend la première lettre de la relation (ex: 'r' pour risque).
                            // md5(uniqid(rand(), true)) ajoute un hash unique pour éviter les collisions d'alias.
                            $newAlias = strtolower(substr($relationName, 0, 1)) . md5(uniqid(rand(), true));

                            // Effectue une LEFT JOIN. 
                            // Utilisez INNER JOIN si le critère DOIT exister dans la relation (ex: si le sinistre doit avoir un risque).
                            // LEFT JOIN est plus souple et inclura les NotificationSinistre même s'il n'y a pas de Risque.
                            $qb->leftJoin($currentAlias . '.' . $relationName, $newAlias);
                            $joinedEntities[$relationName] = $newAlias; // Stocke l'alias pour référence future
                        }
                        // Met à jour l'alias courant pour qu'il pointe vers l'entité jointe
                        $currentAlias = $joinedEntities[$relationName];
                    }
                    // --- FIN DE LA LOGIQUE DE GESTION DES RELATIONS ---

                    // Le reste de la logique de filtrage utilise maintenant $currentAlias et $actualField
                    if (is_array($value) && isset($value['operator'], $value['value'])) {
                        $operator = strtoupper($value['operator']);

                        // Sécurité : valider l'opérateur
                        if (!in_array($operator, $this->allowedOperators, true)) {
                            $status = [
                                "error" => "Opérateur '{$operator}' non autorisé.",
                                "code" => 400,
                            ];
                        }

                        switch ($operator) {
                            case '=':
                                $qb->andWhere($qb->expr()->eq($currentAlias . '.' . $actualField, ':' . $parameterName));
                                break;
                            case '!=': // Ajout de l'opérateur '!=' (différent de)
                                $qb->andWhere($qb->expr()->neq($currentAlias . '.' . $actualField, ':' . $parameterName));
                                break;
                            case '>':
                                $qb->andWhere($qb->expr()->gt($currentAlias . '.' . $actualField, ':' . $parameterName));
                                break;
                            case '<':
                                $qb->andWhere($qb->expr()->lt($currentAlias . '.' . $actualField, ':' . $parameterName));
                                break;
                            case '>=':
                                $qb->andWhere($qb->expr()->gte($currentAlias . '.' . $actualField, ':' . $parameterName));
                                break;
                            case '<=':
                                $qb->andWhere($qb->expr()->lte($currentAlias . '.' . $actualField, ':' . $parameterName));
                                break;
                            case 'LIKE':
                                $qb->andWhere($qb->expr()->like($currentAlias . '.' . $actualField, ':' . $parameterName));
                                // Pour LIKE, la valeur doit être entourée de % pour une recherche partielle
                                $value['value'] = '%' . $value['value'] . '%';
                                break;
                            case 'IN':
                                if (!is_array($value['value'])) {
                                    $status = [
                                        "error" => "La valeur pour l'opérateur '{$operator}' doit être un tableau.",
                                        "code" => 400,
                                    ];
                                }
                                $qb->andWhere($qb->expr()->in($currentAlias . '.' . $actualField, ':' . $parameterName));
                                break;
                            case 'NOT IN':
                                if (!is_array($value['value'])) {
                                    $status = [
                                        "error" => "La valeur pour l'opérateur '{$operator}' doit être un tableau.",
                                        "code" => 400,
                                    ];
                                }
                                $qb->andWhere($qb->expr()->notIn($currentAlias . '.' . $actualField, ':' . $parameterName));
                                break;
                            default:
                                $status = [
                                    "error" => "Opérateur non supporté '{$operator}' pour les critères complexes.",
                                    "code" => 400,
                                ];
                        }
                        $qb->setParameter($parameterName, $value['value']); // La valeur est définie une seule fois
                    } else {
                        // Cas d'un critère simple (chaîne de caractères), utilise LIKE par défaut
                        // Ceci s'applique aussi bien aux champs directs qu'aux champs de relation
                        $qb->andWhere($qb->expr()->like($currentAlias . '.' . $actualField, ':' . $parameterName))
                            ->setParameter($parameterName, '%' . $value . '%');
                    }
                }

                // Appliquer la pagination
                $offset = ($page - 1) * $limit;
                $qb->setFirstResult($offset) // Définir l'offset (à partir de quel élément commencer)
                    ->setMaxResults($limit); // Définir la limite (nombre maximum d'éléments à retourner)

                // 5. Exécuter la requête et récupérer les résultats
                $dataResultat = $qb->getQuery()->getResult(); // Retourne des objets entités Doctrine

                // --- DÉBUT DE LA LOGIQUE DE COMPTAGE DES ÉLÉMENTS TOTAUX ---
                // Il faut un QueryBuilder de comptage séparé qui utilise les MÊMES jointures et critères
                $totalItemsQb = $repository->createQueryBuilder('e_count');
                $joinedEntitiesCount = []; // Les jointures pour le comptage doivent être indépendantes

                // Réappliquer les mêmes critères et jointures pour le comptage
                // Utilise un index de paramètre différent pour éviter les conflits avec le QB principal
                $parameterIndexCount = 0;
                foreach ($criteria as $field => $value) {
                    $parameterNameCount = 'param_count' . $parameterIndexCount++;

                    $fieldParts = explode('.', $field);
                    $currentAliasCount = 'e_count'; // Alias pour le QueryBuilder de comptage
                    $actualFieldCount = $field;

                    if (count($fieldParts) > 1) {
                        $relationName = $fieldParts[0];
                        $actualFieldCount = $fieldParts[1];

                        if (!isset($joinedEntitiesCount[$relationName])) {
                            // Alias unique pour le comptage (différent de celui du QB principal)
                            $newAliasCount = strtolower(substr($relationName, 0, 1)) . 'c' . md5(uniqid(rand(), true));
                            $totalItemsQb->leftJoin($currentAliasCount . '.' . $relationName, $newAliasCount);
                            $joinedEntitiesCount[$relationName] = $newAliasCount;
                        }
                        $currentAliasCount = $joinedEntitiesCount[$relationName];
                    }

                    if (is_array($value) && isset($value['operator'], $value['value'])) {
                        $operator = strtoupper($value['operator']);

                        // Pas de re-validation des opérateurs ici, elle a déjà été faite plus haut.
                        switch ($operator) {
                            case '=':
                                $totalItemsQb->andWhere($totalItemsQb->expr()->eq($currentAliasCount . '.' . $actualFieldCount, ':' . $parameterNameCount));
                                break;
                            case '!=':
                                $totalItemsQb->andWhere($totalItemsQb->expr()->neq($currentAliasCount . '.' . $actualFieldCount, ':' . $parameterNameCount));
                                break;
                            case '>':
                                $totalItemsQb->andWhere($totalItemsQb->expr()->gt($currentAliasCount . '.' . $actualFieldCount, ':' . $parameterNameCount));
                                break;
                            case '<':
                                $totalItemsQb->andWhere($totalItemsQb->expr()->lt($currentAliasCount . '.' . $actualFieldCount, ':' . $parameterNameCount));
                                break;
                            case '>=':
                                $totalItemsQb->andWhere($totalItemsQb->expr()->gte($currentAliasCount . '.' . $actualFieldCount, ':' . $parameterNameCount));
                                break;
                            case '<=':
                                $totalItemsQb->andWhere($totalItemsQb->expr()->lte($currentAliasCount . '.' . $actualFieldCount, ':' . $parameterNameCount));
                                break;
                            case 'LIKE':
                                $totalItemsQb->andWhere($totalItemsQb->expr()->like($currentAliasCount . '.' . $actualFieldCount, ':' . $parameterNameCount));
                                $value['value'] = '%' . $value['value'] . '%';
                                break;
                            case 'IN':
                                // On ne revérifie pas si c'est un tableau, car la validation a déjà été faite pour le QB principal.
                                $totalItemsQb->andWhere($totalItemsQb->expr()->in($currentAliasCount . '.' . $actualFieldCount, ':' . $parameterNameCount));
                                break;
                            case 'NOT IN':
                                // On ne revérifie pas si c'est un tableau.
                                $totalItemsQb->andWhere($totalItemsQb->expr()->notIn($currentAliasCount . '.' . $actualFieldCount, ':' . $parameterNameCount));
                                break;
                            default:
                                // Ignorer les opérateurs non supportés pour le comptage ou loguer une erreur si nécessaire
                                break;
                        }
                        // Définir le paramètre seulement si l'opérateur est géré et qu'il nécessite un paramètre
                        if (in_array($operator, ['=', '!=', '>', '<', '>=', '<=', 'LIKE', 'IN', 'NOT IN'])) {
                            $totalItemsQb->setParameter($parameterNameCount, $value['value']);
                        }
                    } else {
                        $totalItemsQb->andWhere($totalItemsQb->expr()->like($currentAliasCount . '.' . $actualFieldCount, ':' . $parameterNameCount))
                            ->setParameter($parameterNameCount, '%' . $value . '%');
                    }
                }

                // Obtenir le nom du champ d'identifiant pour le COUNT (généralement 'id')
                // Utilise le metadata de la classe pour obtenir le champ d'ID, plus robuste.
                $identifierField = $repository->getClassMetadata()->getSingleIdentifierFieldName();
                $totalItems = $totalItemsQb->select('count(' . $currentAliasCount . '.' . $identifierField . ')')
                    ->getQuery()
                    ->getSingleScalarResult();
                $totalPages = ceil($totalItems / $limit);

                // Ajouter les informations de pagination au tableau de statut pour le template Twig
                $status['pagination'] = [
                    'currentPage' => $page,
                    'itemsPerPage' => $limit,
                    'totalItems' => $totalItems,
                    'totalPages' => $totalPages,
                ];
            } catch (MappingException $e) {
                // Erreur si l'entité, le champ ou le chemin de relation n'existe pas
                $status = [
                    "error" => "Entité, nom de champ ou chemin de relation invalide. Message : " . $e->getMessage(),
                    "code" => 400,
                ];
            } catch (\Exception $e) {
                // Toute autre erreur inattendue lors de l'exécution de la requête
                $status = [
                    "error" => "Une erreur inattendue est survenue. Message : " . $e->getMessage(),
                    "code" => 500,
                ];
            }
        }

        // Rendre le template Twig avec les données filtrées et les informations de statut/pagination
        return $this->render('admin/notificationsinistre/donnees.html.twig', [
            'pageName' => $this->translator->trans("notificationsinistre_page_name_new"),
            'utilisateur' => $utilisateur,
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'notificationsinistres' => $dataResultat, // Les entités NotificationSinistre trouvées
            'page' => $page, // La page actuelle, utile si la pagination est gérée côté client dans le template
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'status' => $status, // Contient l'erreur ou les infos de pagination
            'activator' => $this->activator,
        ]);
    }
}
