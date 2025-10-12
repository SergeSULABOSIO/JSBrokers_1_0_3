<?php

/**
 * @file Ce fichier contient le contrôleur NotificationSinistreController.
 * @description Ce contrôleur est un CRUD complet pour l'entité `NotificationSinistre`.
 * Il est responsable de :
 * 1. `index()`: Afficher la vue principale de la liste des notifications de sinistre, en utilisant le composant `_view_manager`.
 * 2. Fournir des points de terminaison API pour :
 *    - `getFormApi()`: Obtenir le formulaire de création/édition.
 *    - `submitApi()`: Traiter la soumission du formulaire.
 *    - `getContactsListApi()`, `getPiecesListApi()`, etc. : Charger les listes des collections liées à une notification.
 */

namespace App\Controller\Admin;

use App\Entity\Invite;
use DateTimeImmutable;
use App\Entity\Contact;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Constantes\Constante;
use App\Services\ServiceMonnaies;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use App\Form\NotificationSinistreType;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\PieceSinistre;
use App\Entity\Tache;
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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/notificationsinistre", name: 'admin.notificationsinistre.')]
#[IsGranted('ROLE_USER')]
class NotificationSinistreController extends AbstractController
{
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
    ) {}


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request, Constante $constante)
    {
        $data = $this->notificationSinistreRepository->findAll();
        $entityCanvas = $constante->getEntityCanvas(NotificationSinistre::class);

        //On charge les valeurs calculées
        $constante->loadCalculatedValue($entityCanvas, $data);

        return $this->render('components/_view_manager.html.twig', [
            'data' => $data,
            'entite_nom' => "NotificationSinistre",
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(NotificationSinistre::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new NotificationSinistre(), $idEntreprise),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
        ]);
    }


    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?NotificationSinistre $notification, Request $request, Constante $constante): Response
    {
        // MISSION 3 : Récupérer l'idEntreprise depuis la requête.
        $idEntreprise = $request->query->get('idEntreprise');
        $idInvite = $request->query->get('idInvite');

        if (!$idEntreprise) {
            // Fallback pour les cas où il n'est pas passé (ex: collections)
            $entreprise = $this->getEntreprise();
        } else {
            $entreprise = $this->entrepriseRepository->find($idEntreprise);
        }
        if (!$entreprise) throw $this->createNotFoundException("L'entreprise n'a pas été trouvée pour générer le formulaire.");

        if (!$idInvite) {
            // Fallback pour les cas où il n'est pas passé (ex: collections)
            $invite = $this->getInvite();
        } else {
            $invite = $this->inviteRepository->find($idInvite);
        }
        if (!$invite || $invite->getEntreprise()->getId() !== $entreprise->getId()) {
            throw $this->createAccessDeniedException("Vous n'avez pas les droits pour générer ce formulaire.");
        }
        
        if (!$notification) {
            $notification = new NotificationSinistre();
            $notification->setNotifiedAt(new DateTimeImmutable("now"));
            $notification->setInvite($invite);
        }

        $form = $this->createForm(NotificationSinistreType::class, $notification);

        $entityCanvas = $constante->getEntityCanvas(NotificationSinistre::class);
        $constante->loadCalculatedValue($entityCanvas, [$notification]);
        $entityFormCanvas = $constante->getEntityFormCanvas($notification, $entreprise->getId()); // On utilise l'ID de l'entreprise validée

        // dd("ICI", $entityFormCanvas, $entityCanvas, $notification);

        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $entityFormCanvas,
            'entityCanvas' => $entityCanvas
        ]);
    }


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
        $entityCanvas = $constante->getEntityCanvas(NotificationSinistre::class);

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
            'listeCanvas' => $this->constante->getListeCanvas(NotificationSinistre::class),
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
        $entityCanvas = $constante->getEntityCanvas(NotificationSinistre::class);

        // --- AJOUT : BOUCLE D'AUGMENTATION DES DONNÉES ---
        $constante->loadCalculatedValue($entityCanvas, $reponseData["data"]);
        // $this->loadCalculatedValue($entityCanvas, $reponseData["data"], $constante);

        // 6. Rendre le template Twig avec les données filtrées et les informations de statut/pagination
        return $this->render('components/_list_content.html.twig', [
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
            // 'numericAttributes' => $this->constante->getNumericAttributes(new NotificationSinistre()),
            'listeCanvas' => $this->constante->getListeCanvas(NotificationSinistre::class),
            'entityCanvas' => $entityCanvas,
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($reponseData["data"]),
        ]);
    }

    private function getEntreprise(): Entreprise
    {
        /** @var Invite $invite */
        $invite = $this->getInvite();
        return $invite->getEntreprise();
    }

    private function getInvite(): Invite
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());
        return $invite;
    }

    /**
     * Retourne la liste des contacts pour une notification de sinistre donnée.
     */
    #[Route('/api/{id}/contacts', name: 'api.get_contacts', methods: ['GET'])]
    public function getContactsListApi(int $id): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var NotificationSinistre $notification */
            $notification = $this->notificationSinistreRepository->find($id);
            $data = $notification->getContacts();
        }
        $contactCanvas = $this->constante->getEntityCanvas(Contact::class);
        $this->constante->loadCalculatedValue($contactCanvas, $data);

        return $this->render('components/_generic_list_component.html.twig', [
            'data' => $data,
            'entite_nom' => 'Contacts',
            'entityCanvas' => $contactCanvas,
            'listeCanvas' => $this->constante->getListeCanvas(Contact::class),
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Contact(), $this->getEntreprise()->getId()),
            'constante' => $this->constante,
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data),
        ]);
    }


    #[Route('/api/{id}/pieces', name: 'api.get_pieces', methods: ['GET'])]
    public function getPiecesListApi(int $id): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var NotificationSinistre $notification */
            $notification = $this->notificationSinistreRepository->find($id);
            $data = $notification->getPieces();
        }
        $pieceCanvas = $this->constante->getEntityCanvas(PieceSinistre::class);
        $this->constante->loadCalculatedValue($pieceCanvas, $data);

        return $this->render('components/_generic_list_component.html.twig', [
            'data' => $data,
            'entite_nom' => 'Pièces',
            'entityCanvas' => $pieceCanvas,
            'listeCanvas' => $this->constante->getListeCanvas(PieceSinistre::class),
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new PieceSinistre(), $this->getEntreprise()->getId()),
            'constante' => $this->constante,
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data),
        ]);
    }


    #[Route('/api/{id}/taches', name: 'api.get_taches', methods: ['GET'])]
    public function getTachesListApi(int $id): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var NotificationSinistre $notification */
            $notification = $this->notificationSinistreRepository->find($id);
            $data = $notification->getTaches();
        }
        $tacheCanvas = $this->constante->getEntityCanvas(Tache::class);
        $this->constante->loadCalculatedValue($tacheCanvas, $data);

        return $this->render('components/_generic_list_component.html.twig', [
            'data' => $data,
            'entite_nom' => 'Taches',
            'entityCanvas' => $tacheCanvas,
            'listeCanvas' => $this->constante->getListeCanvas(Tache::class),
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Tache(), $this->getEntreprise()->getId()),
            'constante' => $this->constante,
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data),
        ]);
    }


    #[Route('/api/{id}/offreIndemnisationSinistres', name: 'api.get_offreIndemnisationSinistres', methods: ['GET'])]
    public function getOffresIndemnisationListApi(int $id): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var NotificationSinistre $notification */
            $notification = $this->notificationSinistreRepository->find($id);
            $data = $notification->getOffreIndemnisationSinistres();
        }
        $offreCanvas = $this->constante->getEntityCanvas(OffreIndemnisationSinistre::class);
        $this->constante->loadCalculatedValue($offreCanvas, $data);

        return $this->render('components/_generic_list_component.html.twig', [
            'data' => $data,
            'entite_nom' => 'Offres',
            'entityCanvas' => $offreCanvas,
            'listeCanvas' => $this->constante->getListeCanvas(OffreIndemnisationSinistre::class),
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new OffreIndemnisationSinistre(), $this->getEntreprise()->getId()),
            'constante' => $this->constante,
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data),
        ]);
    }
}
