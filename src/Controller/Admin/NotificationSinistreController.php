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
use App\Services\JSBDynamicSearchService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\NotificationSinistreRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

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
        $constante->loadCalculatedValue($entityCanvas, $data);
        // $this->loadCalculatedValue($entityCanvas, $data, $constante);

        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        // dd("ICI");

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
    public function getFormApi(?NotificationSinistre $notification, Constante $constante): Response
    {

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Entreprise $entreprise */
        $entreprise = $invite->getEntreprise();

        if (!$notification) {
            $notification = new NotificationSinistre();
            $notification->setNotifiedAt(new DateTimeImmutable("now"));
            $notification->setInvite($invite);
        }

        $form = $this->createForm(NotificationSinistreType::class, $notification);

        $entityCanvas = $constante->getEntityCanvas($notification);
        $constante->loadCalculatedValue($entityCanvas, [$notification]);

        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $constante->getEntityFormCanvas($notification, $entreprise->getId()),
            'entityCanvas' => $constante->getEntityCanvas($notification)
        ]);
    }



    // public function loadCalculatedValue($entityCanvas, $data, Constante $constante)
    // {
    //     foreach ($data as $entity) {
    //         foreach ($entityCanvas['liste'] as $field) {
    //             if ($field['type'] === 'Calcul') {
    //                 $functionName = $field['fonction'];
    //                 $args = [];
    //                 if (!empty($field['params'])) {
    //                     $paramNames = $field['params'];
    //                     $args = array_map(function ($paramName) use ($entity) {
    //                         $getter = 'get' . ucfirst($paramName);
    //                         return method_exists($entity, $getter) ? $entity->$getter() : null;
    //                     }, $paramNames);
    //                 } else {
    //                     $args[] = $entity;
    //                 }
    //                 if (method_exists($constante, $functionName)) {
    //                     $calculatedValue = $constante->$functionName(...$args);
    //                     $entity->{$field['code']} = $calculatedValue;
    //                 }
    //             }
    //         }
    //     }
    // }


    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var NotificationSinistre $notification */
        $notification = new NotificationSinistre();

        $data = $request->request->all();
        $files = $request->files->all();
        $submittedData = array_merge($data, $files);

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
        $form->submit($submittedData, false); //puisque les données sont fournies ici sous forme de JSON. On ne peut pas utiliser handleRequest

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($notification);
            $em->flush();

            // On sérialise l'entité complète (avec son nouvel ID) pour la renvoyer
            $jsonEntity = $serializer->serialize($notification, 'json', ['groups' => 'list:read']);
            return $this->json([
                'message' => 'Enregistrée avec succès!',
                'entity' => json_decode($jsonEntity) // On renvoie l'objet JSON
            ]);
        }

        $errors = [];
        // On parcourt toutes les erreurs du formulaire (y compris celles des champs enfants)
        foreach ($form->getErrors(true) as $error) {
            $errors[$error->getOrigin()->getName()][] = $error->getMessage();
        }

        return $this->json([
            'success' => false,
            'message' => 'Veuillez corriger les erreurs ci-dessous.',
            'errors'  => $errors // On envoie le tableau détaillé des erreurs au client
        ], 422); // 422 = Unprocessable Entity
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
        $constante->loadCalculatedValue($entityCanvas, $data);
        // $this->loadCalculatedValue($entityCanvas, $data, $constante);

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
        $constante->loadCalculatedValue($entityCanvas, $reponseData["data"]);
        // $this->loadCalculatedValue($entityCanvas, $reponseData["data"], $constante);

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

    /**
     * Retourne la liste des contacts pour une notification de sinistre donnée.
     */
    #[Route('/api/{id}/contacts', name: 'api.get_contacts', methods: ['GET'])]
    public function getContactsListApi(int $id, NotificationSinistreRepository $repository): Response
    {
        $notification = null;
        if ($id === 0) {
            $notification = new NotificationSinistre();
        } else {
            $notification = $repository->find($id);
        }
        if (!$notification) {
            $notification = new NotificationSinistre();
        }
        return $this->render('components/_collection_list.html.twig', [
            'items' => $notification->getContacts(),
            'item_template' => 'components/collection_items/_contact_item.html.twig'
        ]);
    }


    #[Route('/api/{id}/pieces', name: 'api.get_pieces', methods: ['GET'])]
    public function getPiecesListApi(int $id, NotificationSinistreRepository $repository): Response
    {
        $notification = null;
        if ($id === 0) {
            $notification = new NotificationSinistre();
        } else {
            $notification = $repository->find($id);
        }
        if (!$notification) {
            $notification = new NotificationSinistre();
        }
        return $this->render('components/_collection_list.html.twig', [
            'items' => $notification->getPieces(),
            'item_template' => 'components/collection_items/_piece_sinistre_item.html.twig'
        ]);
    }


    #[Route('/api/{id}/taches', name: 'api.get_taches', methods: ['GET'])]
    public function getTachesListApi(int $id, NotificationSinistreRepository $repository): Response
    {
        $notification = null;
        if ($id === 0) {
            $notification = new NotificationSinistre();
        } else {
            $notification = $repository->find($id);
        }
        if (!$notification) {
            $notification = new NotificationSinistre();
        }
        return $this->render('components/_collection_list.html.twig', [
            'items' => $notification->getTaches(),
            'item_template' => 'components/collection_items/_tache_item.html.twig'
        ]);
    }


    #[Route('/api/{id}/offreIndemnisationSinistres', name: 'api.get_offreIndemnisationSinistres', methods: ['GET'])]
    public function getOffresIndemnisationListApi(int $id, NotificationSinistreRepository $repository): Response
    {
        $notification = null;
        if ($id === 0) {
            $notification = new NotificationSinistre();
        } else {
            $notification = $repository->find($id);
        }
        if (!$notification) {
            $notification = new NotificationSinistre();
        }
        return $this->render('components/_collection_list.html.twig', [
            'items' => $notification->getOffreIndemnisationSinistres(),
            'item_template' => 'components/collection_items/_offre_indemnisation_sinistre_item.html.twig'
        ]);
    }
}
