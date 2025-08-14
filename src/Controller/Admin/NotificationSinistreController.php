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
use App\Services\JSBDynamicSearchService;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route("/admin/notificationsinistre", name: 'admin.notificationsinistre.')]
#[IsGranted('ROLE_USER')]
class NotificationSinistreController extends AbstractController
{
    public MenuActivator $activator;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private NotificationSinistreRepository $notificationSinistreRepository,
        private Constante $constante,
        private ServiceMonnaies $serviceMonnaies,
        private JSBDynamicSearchService $searchService, // Ajoutez cette ligne
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_CLAIMS);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request, Constante $constante)
    {
        $page = $request->query->getInt("page", 1);
        $status = [
            "error" => "Données",
            "code" => 200,
            "message" => "Initialisation réussi."
        ];

        // 1. On récupère les données brutes de la base de données
        $data = $this->notificationSinistreRepository->paginateForEntreprise($idEntreprise, $page);

        // 2. On récupère la "recette" d'affichage depuis le canvas
        $entityCanvas = $constante->getEntityCanvas(new NotificationSinistre());

        // --- AJOUT : BOUCLE D'AUGMENTATION DES DONNÉES ---
        $this->loadCalculatedValue($entityCanvas, $data, $constante);

        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        return $this->render('components/_rubrique_list_index.html.twig', [
            'entreprise' => $entreprise,
            'utilisateur' => $utilisateur,
            'status' => $status, // Contient l'erreur ou les infos de pagination
            'rubrique_nom' => "Notification Sinistre",
            'entite_nom' => "NotificationSinistre",
            'racine_url_controleur_php_nom' => "notificationsinistre",
            'controleur_stimulus_nom' => "notificationsinistre-formulaire",
            'data' => $data, // $data contient maintenant les entités avec les champs calculés
            'page' => $page,
            'limit' => 100,            // La limite par page
            'totalItems' => count($data),  // Le nombre total d'éléments (pour la pagination)
            'constante' => $this->constante,
            'numericAttributes' => $this->constante->getNumericAttributes(new NotificationSinistre()),
            'listeCanvas' => $this->constante->getListeCanvas(new NotificationSinistre()),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new NotificationSinistre(), $entreprise->getId()),
        ]);
    }


    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi($id): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Entreprise $entreprise */
        $entreprise = $invite->getEntreprise();

        /** @var NotificationSinistre $notification */
        $notification = null;

        if ($id) {
            $notification = $this->notificationSinistreRepository->find($id);
            if (!$notification) {
                return new Response('Entité non trouvée', 404);
            }
        } else {
            $notification = new NotificationSinistre();
            $notification->setCreatedAt(new DateTimeImmutable("now"));
            $notification->setInvite($invite);
        }
        $notification->setUpdatedAt(new DateTimeImmutable("now"));

        $form = $this->createForm(NotificationSinistreType::class, $notification);

        // On rend un template qui contient uniquement le formulaire
        return $this->render('admin/notificationsinistre/_form.html.twig', [
            'form' => $form->createView()
        ]);
    }



    public function loadCalculatedValue($entityCanvas, $data, Constante $constante)
    {
        // --- AJOUT : BOUCLE D'AUGMENTATION DES DONNÉES ---
        // On parcourt chaque entité pour y ajouter les valeurs calculées.
        foreach ($data as $entity) {
            // On parcourt la définition du canvas pour trouver les calculs à faire.
            foreach ($entityCanvas['liste'] as $field) {
                if ($field['type'] === 'Calcul') {
                    $functionName = $field['fonction'];
                    $args = [];

                    if (!empty($field['params'])) {
                        // Cas 1 : Des paramètres spécifiques sont listés
                        $paramNames = $field['params'];
                        $args = array_map(function ($paramName) use ($entity) {
                            $getter = 'get' . ucfirst($paramName);
                            return method_exists($entity, $getter) ? $entity->$getter() : null;
                        }, $paramNames);
                    } else {
                        // Cas 2 : On passe l'entité entière
                        $args[] = $entity;
                    }

                    // On appelle la fonction du service 'constante'
                    if (method_exists($constante, $functionName)) {
                        $calculatedValue = $constante->$functionName(...$args);
                        // On ajoute la propriété virtuelle à l'objet entité
                        $entity->{$field['code']} = $calculatedValue;
                    }
                }
            }
        }
        // --- FIN DE L'AJOUT ---
    }


    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em, ValidatorInterface $validator): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var NotificationSinistre $notification */
        $notification = new NotificationSinistre();

        $data = json_decode($request->getContent(), true);
        $notificationId = $data['id'] ?? null;

        if ($notificationId) {
            // Mode "Modification"
            $notification = $this->notificationSinistreRepository->find($notificationId);
            if (!$notification) {
                return $this->json(['success' => false, 'message' => 'Entité non trouvée.'], 404);
            }
        } else {
            // Mode "Création"
            $notification = new NotificationSinistre();
            //Paramètres par défaut
            $notification->setOccuredAt(new DateTimeImmutable("now"));
            $notification->setNotifiedAt(new DateTimeImmutable("now"));
            $notification->setCreatedAt(new DateTimeImmutable("now"));
            $notification->setInvite($invite);
            $notification->setDescriptionDeFait("RAS");
        }
        $notification->setUpdatedAt(new DateTimeImmutable("now"));

        // Utiliser les formulaires Symfony pour la validation est une bonne pratique
        $form = $this->createForm(NotificationSinistreType::class, $notification);
        // Le 'false' permet de ne soumettre que les champs présents dans $data
        $form->submit($data, false); //puisque les données sont fournies ici sous forme de JSON. On ne peut pas utiliser handleRequest

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($notification);
            $em->flush();
            return $this->json([
                'success' => true,
                'message' => 'Enregistrement réussi !',
                'entity' => $notification // On peut renvoyer l'entité mise à jour
            ], 200, [], ['groups' => 'list:read']);
        }

        // Si le formulaire n'est pas valide, on retourne les erreurs
        $messages = "";
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[$error->getOrigin()->getName()][] = $error->getMessage();
            $messages .= $error->getMessage() . " | ";
        }

        return $this->json([
            'success' => false,
            'message' => 'Des erreurs de validation sont survenues. ' . $messages,
            'errors' => $errors
        ], 422); // 422 = Unprocessable Entity (erreur de validation)
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
    public function getlistelementdetails($idEntreprise, $idNotificationsinistre, Request $request, Constante $constante)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var NotificationSinistre $notificationsinistre */
        $notificationsinistre = $this->notificationSinistreRepository->find($idNotificationsinistre);
        $data[] = $notificationsinistre;

        // 2. On récupère la "recette" d'affichage depuis le canvas
        $entityCanvas = $constante->getEntityCanvas(new NotificationSinistre());

        // --- AJOUT : BOUCLE D'AUGMENTATION DES DONNÉES ---
        $this->loadCalculatedValue($entityCanvas, $data, $constante);

        //On se dirie vers la page le formulaire d'édition
        return $this->render('admin/notificationsinistre/elementview.html.twig', [
            'notificationsinistre' => $data[0],
            'entreprise' => $entreprise,
            'utilisateur' => $user,
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'listeCanvas' => $this->constante->getListeCanvas(new NotificationSinistre()),
            'entityCanvas' => $entityCanvas,
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


    #[Route('/api/dynamic-query/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['POST'])]
    public function query($idEntreprise, Request $request, Constante $constante)
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser(); // Vous pouvez l'utiliser pour des logiques de droits si nécessaire.

        // On récupère les données JSON du corps de la requête
        $requestData = json_decode($request->getContent(), true) ?? [];
        // On appelle le service pour obtenir les résultats
        $reponseData = $this->searchService->search($requestData);

        // 2. On récupère la "recette" d'affichage depuis le canvas
        $entityCanvas = $constante->getEntityCanvas(new NotificationSinistre());

        // --- AJOUT : BOUCLE D'AUGMENTATION DES DONNÉES ---
        $this->loadCalculatedValue($entityCanvas, $reponseData["data"], $constante);

        // 6. Rendre le template Twig avec les données filtrées et les informations de statut/pagination
        return $this->render('components/_list_donnees.html.twig', [
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'utilisateur' => $utilisateur,
            'constante' => $this->constante,
            'rubrique_nom' => "Notification Sinistre",
            'entite_nom' => "NotificationSinistre",
            'racine_url_controleur_php_nom' => "notificationsinistre",
            'controleur_stimulus_nom' => "notificationsinistre-formulaire",
            'status' => $reponseData["status"], // Contient l'erreur ou les infos de pagination
            'data' => $reponseData["data"], // Les entités NotificationSinistre trouvées
            'page' => $reponseData["page"], // La page actuelle, utile si la pagination est gérée côté client dans le template
            'limit' => $reponseData["limit"],            // La limite par page
            'totalItems' => $reponseData["totalItems"],  // Le nombre total d'éléments (pour la pagination)
            'numericAttributes' => $this->constante->getNumericAttributes(new NotificationSinistre()),
            'listeCanvas' => $this->constante->getListeCanvas(new NotificationSinistre()),
            'entityCanvas' => $entityCanvas,
        ]);
    }
}
