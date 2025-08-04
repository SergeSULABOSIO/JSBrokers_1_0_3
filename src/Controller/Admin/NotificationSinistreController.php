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
        $status = [
            "error" => "Données",
            "code" => 200,
            "message" => "Initialisation réussi."
        ];

        $data = $this->notificationSinistreRepository->paginateForEntreprise($idEntreprise, $page);

        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        return $this->render('admin/notificationsinistre/index.html.twig', [
            'status' => $status, // Contient l'erreur ou les infos de pagination
            'pageName' => $this->translator->trans("notificationsinistre_page_name_new"),
            'utilisateur' => $utilisateur,
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'notificationsinistres' => $data,
            'page' => $page,
            'limit' => 100,            // La limite par page
            'totalItems' => count($data),  // Le nombre total d'éléments (pour la pagination)
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'numericAttributes' => $this->constante->getNumericAttributes(new NotificationSinistre()),
            'listeCanvas' => $this->constante->getListeCanvas(new NotificationSinistre()),
        ]);
    }


    #[Route('/create/{idEntreprise}', name: 'create')]
    public function create($idEntreprise, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        $status = [
            "error" => "Données",
            "code" => 200,
            "message" => "Chargement réussi."
        ];

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
            'status' => $status, // Contient l'erreur ou les infos de pagination
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

        $status = [
            "error" => "Données",
            "code" => 200,
            "message" => "Chargement réussi."
        ];

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
            'status' => $status, // Contient l'erreur ou les infos de pagination
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
            'listeCanvas' => $this->constante->getListeCanvas(new NotificationSinistre()),
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

        $status = [
            "error" => "Données",
            "code" => 200,
            "message" => "Actualisation réussi."
        ];

        $data = $this->notificationSinistreRepository->paginateForEntreprise($idEntreprise, $page);

        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        return $this->render('admin/notificationsinistre/donnees.html.twig', [
            'status' => $status, // Contient l'erreur ou les infos de pagination
            'pageName' => $this->translator->trans("notificationsinistre_page_name_new"),
            'utilisateur' => $utilisateur,
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'notificationsinistres' => $data,
            'page' => $page,
            'limit' => 100,            // La limite par page
            'totalItems' => count($data),  // Le nombre total d'éléments (pour la pagination)
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'listeCanvas' => $this->constante->getListeCanvas(new NotificationSinistre()),
            'activator' => $this->activator,
        ]);
    }



    #[Route('/api/dynamic-query/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['POST'])]
    public function query($idEntreprise, Request $request)
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser(); // Vous pouvez l'utiliser pour des logiques de droits si nécessaire.

        $status = [
            "error" => null,
            "code" => 200, // Code HTTP par défaut OK
            "message" => "Requête exécutée avec succès."
        ];
        $totalItems = 0;    // Compteur total pour la pagination

        // 1. Récupérer et décoder les données de la requête POST (JSON)
        $data = json_decode($request->getContent(), true);

        // Vérifier si le JSON est valide
        if (json_last_error() !== JSON_ERROR_NONE) {
            $status = [
                "error" => 'Format JSON invalide dans la requête.',
                "code" => 400,
                "message" => "Le corps de la requête n'est pas un JSON valide."
            ];
            // Puisque nous rendons un template, nous continuons l'exécution pour que le template puisse afficher l'erreur.
        }

        // Extraire les paramètres de la requête avec des valeurs par défaut sécurisées
        $entityName = $data['entityName'] ?? null;
        $criteria = $data['criteria'] ?? []; // Toujours un tableau, même vide
        $page = (int) ($data['page'] ?? 1);
        $limit = (int) ($data['limit'] ?? 10);

        // Validation basique des paramètres de pagination
        $page = max(1, $page); // La page minimale est 1
        $limit = max(1, min(100, $limit)); // La limite entre 1 et 100 pour éviter des requêtes trop lourdes.

        // 2. Valider les données d'entrée fournies par le frontend
        if (!$entityName || !is_array($criteria)) {
            $status = [
                "error" => 'Paramètres manquants ou invalides.',
                "code" => 400,
                "message" => "Les paramètres 'entityName' et 'criteria' (qui doit être un tableau) sont requis."
            ];
            // Puisque nous rendons un template, nous continuons l'exécution pour que le template puisse afficher l'erreur.
        }

        // 3. Vérifier si l'entité demandée est autorisée pour la recherche dynamique (sécurité)
        if (!in_array($entityName, $this->allowedEntities, true)) {
            $status = [
                "error" => "Entité non autorisée.",
                "code" => 403,
                "message" => "L'interrogation de l'entité '{$entityName}' n'est pas autorisée par ce service."
            ];
            // Puisque nous rendons un template, nous continuons l'exécution pour que le template puisse afficher l'erreur.
        }

        try {
            // Obtenir le repository de l'entité demandée pour construire la requête.
            // Le chemin complet de la classe est 'App\\Entity\\NomDeLEntite'.
            $repository = $this->em->getRepository('App\\Entity\\' . $entityName);
            // 'e' est l'alias de notre entité principale dans la requête DQL (ex: SELECT e FROM App\Entity\NotificationSinistre e)
            $qb = $repository->createQueryBuilder('e');

            // Tableau pour stocker les alias des entités déjà jointes.
            // Cela est crucial pour éviter de joindre la même entité plusieurs fois si plusieurs critères y font référence.
            $joinedEntities = [];

            // Index pour garantir des noms de paramètres uniques dans la requête DQL.
            // Ex: :param0, :param1, etc. pour éviter les conflits si plusieurs critères utilisent le même nom de champ.
            $parameterIndex = 0;

            // --- BOUCLE PRINCIPALE DE CONSTRUCTION DES FILTRES ---
            // On parcourt chaque critère fourni par le frontend.
            foreach ($criteria as $field => $value) {
                // Création d'un nom de paramètre unique pour Doctrine.
                // str_replace('.', '_', $field) remplace les points dans les noms de champs de relation (ex: assure.nom devient assure_nom).
                $parameterName = str_replace('.', '_', $field) . '_' . $parameterIndex++;

                // Alias de l'entité actuelle et nom du champ réel à utiliser dans la clause WHERE.
                // Par défaut, ils pointent vers l'entité principale ('e') et le champ tel quel.
                $currentAlias = 'e';
                $actualField = $field;

                // --- GESTION DES RELATIONS (Jointures) ---
                // Si le nom du champ contient un point, cela indique une relation (ex: "assure.nom").
                $fieldParts = explode('.', $field);
                if (count($fieldParts) > 1) {
                    $relationName = $fieldParts[0]; // Le nom de la relation (ex: "assure")
                    $actualField = $fieldParts[1];   // Le nom du champ sur l'entité liée (ex: "nom")

                    // Vérifier si cette relation n'a pas déjà été jointe pour éviter les doublons.
                    if (!isset($joinedEntities[$relationName])) {
                        // Effectuer une LEFT JOIN.
                        // Utilisez LEFT JOIN si vous voulez inclure les entités principales même si la relation n'existe pas.
                        // Utilisez INNER JOIN si le critère DOIT avoir une correspondance dans la relation pour être inclus.
                        $qb->leftJoin("{$currentAlias}.{$relationName}", $relationName); // Joint 'e.assure' comme 'assure'
                        $joinedEntities[$relationName] = $relationName; // Stocke l'alias utilisé pour cette relation.
                    }
                    // Mettre à jour l'alias courant pour qu'il pointe vers l'entité jointe.
                    $currentAlias = $joinedEntities[$relationName];
                }

                // --- DÉBUT DE LA LOGIQUE DE FILTRAGE DES VALEURS ---
                // Distinguer les critères simples (chaîne directe) des critères complexes (tableau avec opérateur et valeur).
                if (is_array($value) && isset($value['operator']) && (isset($value['value']) && $value['value'] !== '')) {
                    // C'est un critère complexe (ex: pour nombres, dates, plages de dates).
                    $operator = strtoupper($value['operator']); // Convertir l'opérateur en majuscules pour normalisation.
                    $filterValue = $value['value'];

                    // Sécurité : valider l'opérateur contre la liste blanche autorisée.
                    if (!in_array($operator, $this->allowedOperators, true)) {
                        // throw new \InvalidArgumentException("Opérateur '{$operator}' non autorisé pour le champ '{$field}'.");
                        $status = [
                            "error" => "Opérateur non autorisé.",
                            "code" => 403,
                            "message" => "Opérateur '{$operator}' non autorisé pour le champ '{$field}'."
                        ];
                    }

                    // Logique spécifique pour les plages de dates (opérateur BETWEEN)
                    if ($operator === 'BETWEEN') {
                        if (!is_array($filterValue) || (!isset($filterValue['from']) && !isset($filterValue['to']))) {
                            // throw new \InvalidArgumentException("La valeur pour l'opérateur BETWEEN doit être un tableau avec 'from' et/ou 'to'.");
                            $status = [
                                "error" => "Opérateur non conforme au format.",
                                "code" => 403,
                                "message" => "La valeur pour l'opérateur BETWEEN doit être un tableau avec 'from' et/ou 'to'."
                            ];
                        }

                        $from = $filterValue['from'] ?? null;
                        $to = $filterValue['to'] ?? null;

                        if ($from && $to) {
                            $fromObj = new \DateTime($from);
                            $toObj = new \DateTime($to);
                            $qb->andWhere("{$currentAlias}.{$actualField} BETWEEN :{$parameterName}_from AND :{$parameterName}_to");
                            $qb->setParameter("{$parameterName}_from", $fromObj->format('Y-m-d 00:00:00'));
                            $qb->setParameter("{$parameterName}_to", $toObj->format('Y-m-d 23:59:59'));
                        } elseif ($from) {
                            $fromObj = new \DateTime($from);
                            $qb->andWhere("{$currentAlias}.{$actualField} >= :{$parameterName}_from");
                            $qb->setParameter("{$parameterName}_from", $fromObj->format('Y-m-d 00:00:00'));
                        } elseif ($to) {
                            $toObj = new \DateTime($to);
                            $qb->andWhere("{$currentAlias}.{$actualField} <= :{$parameterName}_to");
                            $qb->setParameter("{$parameterName}_to", $toObj->format('Y-m-d 23:59:59'));
                        }
                    } else {
                        // Logique pour les autres opérateurs (y compris les dates simples avec =, !=, >, etc.)
                        // Gérer les conversions de date pour les champs date (s'ils ne sont pas déjà des objets DateTime)
                        $metadata = $this->em->getClassMetadata($repository->getClassName());
                        if ($metadata->hasField($actualField) && in_array($metadata->getTypeOfField($actualField), ['datetime', 'datetime_immutable', 'date', 'date_immutable'])) {
                            try {
                                $dateObj = new \DateTime($filterValue);
                                // Ajuster les valeurs des paramètres pour couvrir toute la journée si l'opérateur est '=' ou '!='
                                if ($operator === '=') {
                                    $qb->andWhere("{$currentAlias}.{$actualField} BETWEEN :{$parameterName}_start AND :{$parameterName}_end");
                                    $qb->setParameter("{$parameterName}_start", $dateObj->format('Y-m-d 00:00:00'));
                                    $qb->setParameter("{$parameterName}_end", $dateObj->format('Y-m-d 23:59:59'));
                                } elseif ($operator === '!=') {
                                    $qb->andWhere("{$currentAlias}.{$actualField} NOT BETWEEN :{$parameterName}_start AND :{$parameterName}_end");
                                    $qb->setParameter("{$parameterName}_start", $dateObj->format('Y-m-d 00:00:00'));
                                    $qb->setParameter("{$parameterName}_end", $dateObj->format('Y-m-d 23:59:59'));
                                } elseif ($operator === '>') {
                                    $qb->andWhere("{$currentAlias}.{$actualField} > :{$parameterName}");
                                    $qb->setParameter($parameterName, $dateObj->format('Y-m-d 23:59:59'));
                                } elseif ($operator === '>=') {
                                    $qb->andWhere("{$currentAlias}.{$actualField} >= :{$parameterName}");
                                    $qb->setParameter($parameterName, $dateObj->format('Y-m-d 00:00:00'));
                                } elseif ($operator === '<') {
                                    $qb->andWhere("{$currentAlias}.{$actualField} < :{$parameterName}");
                                    $qb->setParameter($parameterName, $dateObj->format('Y-m-d 00:00:00'));
                                } elseif ($operator === '<=') {
                                    $qb->andWhere("{$currentAlias}.{$actualField} <= :{$parameterName}");
                                    $qb->setParameter($parameterName, $dateObj->format('Y-m-d 23:59:59'));
                                }
                            } catch (\Exception $e) {
                                // throw new \InvalidArgumentException("Format de date invalide pour le champ '{$field}': " . $filterValue);
                                $status = [
                                    "error" => "Format de la date invalide.",
                                    "code" => 403,
                                    "message" => "Format de date invalide pour le champ '{$field}': " . $filterValue
                                ];
                            }
                        } else {
                            // Pour les opérateurs numériques ou LIKE standard
                            $exprMethod = $qb->expr()->{'__call'}(strtolower($operator), [$currentAlias . '.' . $actualField, ':' . $parameterName]);
                            $qb->andWhere($exprMethod);

                            // Pour LIKE, la valeur doit être entourée de % pour une recherche partielle
                            $paramValue = ($operator === 'LIKE') ? '%' . $filterValue . '%' : $filterValue;
                            $qb->setParameter($parameterName, $paramValue);
                        }
                    }
                } else {
                    // C'est un critère simple (chaîne de caractères directe), utilise LIKE par défaut pour la recherche textuelle.
                    // S'applique aussi bien aux champs directs qu'aux champs de relation.
                    // Ex: 'descriptionDeFait': 'accident' => e.descriptionDeFait LIKE '%accident%'
                    if (is_string($value) && $value !== '') {
                        $qb->andWhere($qb->expr()->like($currentAlias . '.' . $actualField, ':' . $parameterName))
                            ->setParameter($parameterName, '%' . $value . '%');
                    }
                    // Si $value est vide ou non string, on ignore ce critère.
                }
            } // --- FIN DE LA BOUCLE PRINCIPALE DE CONSTRUCTION DES FILTRES ---


            // Appliquer la pagination à la requête principale
            $offset = ($page - 1) * $limit;
            $qb->setFirstResult($offset) // Définir l'offset (à partir de quel élément commencer)
                ->setMaxResults($limit); // Définir la limite (nombre maximum d'éléments à retourner)

            // Exécuter la requête pour obtenir les résultats (objets Doctrine)
            $results = $qb->getQuery()->getResult();

            // --- Logique de COMPTAGE DES ÉLÉMENTS TOTAUX ---
            // Il est essentiel de calculer le nombre total de résultats SANS la pagination,
            // mais AVEC tous les filtres appliqués, pour informer le frontend du nombre total de pages.
            // On clone le QueryBuilder principal pour ne pas perturber sa configuration de pagination.
            $totalItemsQb = $repository->createQueryBuilder('e_count');

            // Réappliquer les mêmes jointures et critères pour le QueryBuilder de comptage.
            // Il est crucial que les alias et paramètres soient uniques pour ce QB de comptage.
            $joinedEntitiesCount = []; // Un nouveau tableau de suivi des jointures pour le comptage.
            $parameterIndexCount = 0;  // Un nouvel index de paramètre pour le comptage.

            foreach ($criteria as $field => $value) {
                $parameterNameCount = str_replace('.', '_', $field) . '_count_' . $parameterIndexCount++;

                $currentAliasCount = 'e_count'; // Alias pour le QueryBuilder de comptage
                $actualFieldCount = $field;

                $fieldParts = explode('.', $field);
                if (count($fieldParts) > 1) {
                    $relationName = $fieldParts[0];
                    $actualFieldCount = $fieldParts[1];

                    if (!isset($joinedEntitiesCount[$relationName])) {
                        $totalItemsQb->leftJoin("{$currentAliasCount}.{$relationName}", $relationName . '_count'); // Utilise un alias distinct pour le comptage
                        $joinedEntitiesCount[$relationName] = $relationName . '_count';
                    }
                    $currentAliasCount = $joinedEntitiesCount[$relationName];
                }

                // Réappliquer la logique de filtrage pour le comptage (identique à la requête principale)
                if (is_array($value) && isset($value['operator']) && (isset($value['value']) && $value['value'] !== '')) {
                    $operator = strtoupper($value['operator']);
                    $filterValue = $value['value'];

                    if ($operator === 'BETWEEN') {
                        $from = $filterValue['from'] ?? null;
                        $to = $filterValue['to'] ?? null;
                        if ($from && $to) {
                            $fromObj = new \DateTime($from);
                            $toObj = new \DateTime($to);
                            $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} BETWEEN :{$parameterNameCount}_from AND :{$parameterNameCount}_to");
                            $totalItemsQb->setParameter("{$parameterNameCount}_from", $fromObj->format('Y-m-d 00:00:00'));
                            $totalItemsQb->setParameter("{$parameterNameCount}_to", $toObj->format('Y-m-d 23:59:59'));
                        } elseif ($from) {
                            $fromObj = new \DateTime($from);
                            $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} >= :{$parameterNameCount}_from");
                            $totalItemsQb->setParameter("{$parameterNameCount}_from", $fromObj->format('Y-m-d 00:00:00'));
                        } elseif ($to) {
                            $toObj = new \DateTime($to);
                            $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} <= :{$parameterNameCount}_to");
                            $totalItemsQb->setParameter("{$parameterNameCount}_to", $toObj->format('Y-m-d 23:59:59'));
                        }
                    } else {
                        $metadata = $this->em->getClassMetadata($repository->getClassName());
                        if ($metadata->hasField($actualFieldCount) && in_array($metadata->getTypeOfField($actualFieldCount), ['datetime', 'datetime_immutable', 'date', 'date_immutable'])) {
                            try {
                                $dateObj = new \DateTime($filterValue);
                                if ($operator === '=') {
                                    $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} BETWEEN :{$parameterNameCount}_start AND :{$parameterNameCount}_end");
                                    $totalItemsQb->setParameter("{$parameterNameCount}_start", $dateObj->format('Y-m-d 00:00:00'));
                                    $totalItemsQb->setParameter("{$parameterNameCount}_end", $dateObj->format('Y-m-d 23:59:59'));
                                } elseif ($operator === '!=') {
                                    $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} NOT BETWEEN :{$parameterNameCount}_start AND :{$parameterNameCount}_end");
                                    $totalItemsQb->setParameter("{$parameterNameCount}_start", $dateObj->format('Y-m-d 00:00:00'));
                                    $totalItemsQb->setParameter("{$parameterNameCount}_end", $dateObj->format('Y-m-d 23:59:59'));
                                } elseif ($operator === '>') {
                                    $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} > :{$parameterNameCount}");
                                    $totalItemsQb->setParameter($parameterNameCount, $dateObj->format('Y-m-d 23:59:59'));
                                } elseif ($operator === '>=') {
                                    $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} >= :{$parameterNameCount}");
                                    $totalItemsQb->setParameter($parameterNameCount, $dateObj->format('Y-m-d 00:00:00'));
                                } elseif ($operator === '<') {
                                    $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} < :{$parameterNameCount}");
                                    $totalItemsQb->setParameter($parameterNameCount, $dateObj->format('Y-m-d 00:00:00'));
                                } elseif ($operator === '<=') {
                                    $totalItemsQb->andWhere("{$currentAliasCount}.{$actualFieldCount} <= :{$parameterNameCount}");
                                    $totalItemsQb->setParameter($parameterNameCount, $dateObj->format('Y-m-d 23:59:59'));
                                }
                            } catch (\Exception $e) {
                                // Ignorer les erreurs de date pour le comptage si déjà gérées par la requête principale
                            }
                        } else {
                            $exprMethod = $totalItemsQb->expr()->{'__call'}(strtolower($operator), [$currentAliasCount . '.' . $actualFieldCount, ':' . $parameterNameCount]);
                            $totalItemsQb->andWhere($exprMethod);
                            $paramValue = ($operator === 'LIKE') ? '%' . $filterValue . '%' : $filterValue;
                            $totalItemsQb->setParameter($parameterNameCount, $paramValue);
                        }
                    }
                } else {
                    if (is_string($value) && $value !== '') {
                        $totalItemsQb->andWhere($totalItemsQb->expr()->like($currentAliasCount . '.' . $actualFieldCount, ':' . $parameterNameCount))
                            ->setParameter($parameterNameCount, '%' . $value . '%');
                    }
                }
            }

            // Sélectionner le COUNT de l'identifiant unique (généralement 'id') pour le comptage total.
            // On utilise l'alias de l'entité de comptage 'e_count'.
            $identifierField = $repository->getClassMetadata()->getSingleIdentifierFieldName();
            $totalItemsQb->select("COUNT(DISTINCT {$totalItemsQb->getRootAliases()[0]}.{$identifierField})") // Utilise le premier alias racine (e_count)
                ->setMaxResults(null)    // Annule la limite
                ->setFirstResult(null); // Annule l'offset

            // Exécuter la requête de comptage
            $totalItems = $totalItemsQb->getQuery()->getSingleScalarResult();

            $status['code'] = 200; // Si tout s'est bien passé
            $status['message'] = "Requête de filtre exécutée avec succès.";
        } catch (\InvalidArgumentException $e) {
            // Capturer les erreurs de validation spécifiques (opérateurs non autorisés, format de date invalide)
            $status = [
                "error" => $e->getMessage(),
                "code" => 400,
                "message" => "Erreur de validation des critères de recherche."
            ];
        } catch (MappingException $e) {
            // Capturer les erreurs si un champ ou une relation n'existe pas
            $status = [
                "error" => "Erreur de mappage Doctrine: " . $e->getMessage(),
                "code" => 500,
                "message" => "Erreur interne lors de l'accès aux propriétés de l'entité. Vérifiez vos champs et relations."
            ];
        } catch (\Exception $e) {
            // Capturer toute autre exception inattendue
            $status = [
                "error" => "Une erreur inattendue est survenue: " . $e->getMessage(),
                "code" => 500,
                "message" => "Erreur interne du serveur lors du traitement de la requête."
            ];
            // Enregistrez l'exception complète pour le débogage (ex: via un service Logger)
            error_log("Erreur dans dynamic-query: " . $e->getMessage() . " sur la ligne " . $e->getLine() . " dans " . $e->getFile());
        }

        // 6. Rendre le template Twig avec les données filtrées et les informations de statut/pagination
        return $this->render('admin/notificationsinistre/donnees.html.twig', [
            'status' => $status, // Contient l'erreur ou les infos de pagination
            'notificationsinistres' => $results, // Les entités NotificationSinistre trouvées
            'pageName' => $this->translator->trans("notificationsinistre_page_name_new"),
            'utilisateur' => $utilisateur,
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'page' => $page, // La page actuelle, utile si la pagination est gérée côté client dans le template
            'limit' => $limit,            // La limite par page
            'totalItems' => $totalItems,  // Le nombre total d'éléments (pour la pagination)
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'listeCanvas' => $this->constante->getListeCanvas(new NotificationSinistre()),
            'activator' => $this->activator,
        ]);
    }
}
