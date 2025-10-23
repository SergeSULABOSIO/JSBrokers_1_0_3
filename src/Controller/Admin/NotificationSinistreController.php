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

use App\Entity\Tache;
use DateTimeImmutable;
use App\Entity\Contact;
use App\Constantes\Constante;
use App\Entity\PieceSinistre;
use App\Services\ServiceMonnaies;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use App\Form\NotificationSinistreType;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use App\Entity\OffreIndemnisationSinistre;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
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
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

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

    protected function getParentAssociationMap(): array
    {
        return [
            'notificationSinistre' => NotificationSinistre::class,
        ];
    }

    #[Route(
        '/index/{idInvite}/{idEntreprise}',
        name: 'index',
        requirements: [
            'idEntreprise' => Requirement::DIGITS,
            'idInvite' => Requirement::DIGITS
        ],
        methods: ['GET', 'POST']
    )]
    public function index(int $idInvite, int $idEntreprise)
    {
        $data = $this->notificationSinistreRepository->findAll();
        $entityCanvas = $this->constante->getEntityCanvas(NotificationSinistre::class);
        $this->constante->loadCalculatedValue($entityCanvas, $data);

        return $this->render('components/_view_manager.html.twig', [
            'data' => $data,
            'entite_nom' => $this->getEntityName($this),
            'serverRootName' => $this->getServerRootName($this),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(NotificationSinistre::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new NotificationSinistre(), $idEntreprise),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
            'idInvite' => $idInvite,
            'idEntreprise' => $idEntreprise,
        ]);
    }


    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?NotificationSinistre $notification, Request $request): Response
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->validateWorkspaceAccess($request);
        $idEntreprise = $entreprise->getId();
        $idInvite = $invite->getId();

        if (!$notification) {
            $notification = new NotificationSinistre();
            $notification->setNotifiedAt(new DateTimeImmutable("now"));
            $notification->setInvite($invite);
        }

        $form = $this->createForm(NotificationSinistreType::class, $notification);

        $entityCanvas = $this->constante->getEntityCanvas(NotificationSinistre::class);
        $this->constante->loadCalculatedValue($entityCanvas, [$notification]);
        $entityFormCanvas = $this->constante->getEntityFormCanvas($notification, $entreprise->getId()); // On utilise l'ID de l'entreprise validée

        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $entityFormCanvas,
            'entityCanvas' => $entityCanvas,
            'idEntreprise' => $idEntreprise,
            'idInvite' => $idInvite,
        ]);
    }


    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): JsonResponse
    {
        $data = $request->request->all();
        $files = $request->files->all();
        $submittedData = array_merge($data, $files);

        /** @var NotificationSinistre $notification */
        $notification = isset($data['id']) ? $em->getRepository(NotificationSinistre::class)->find($data['id']) : new NotificationSinistre();

        $notificationId = $data['id'] ?? null;
        if (!$notificationId) {
            //Paramètres par défaut
            $notification->setOccuredAt(new DateTimeImmutable("now"));
            $notification->setNotifiedAt(new DateTimeImmutable("now"));
            $notification->setCreatedAt(new DateTimeImmutable("now"));
            $notification->setInvite($this->getInvite());
            $notification->setDescriptionDeFait("RAS");
        }
        $notification->setUpdatedAt(new DateTimeImmutable("now"));

        $form = $this->createForm(NotificationSinistreType::class, $notification);
        $form->submit($submittedData, false); //puisque les données sont fournies ici sous forme de JSON. On ne peut pas utiliser handleRequest

        if ($form->isSubmitted() && $form->isValid()) {
            $this->associateParent($notification, $data, $em);
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

    /**
     * Supprime une pièce.
     */
    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(NotificationSinistre $notification, EntityManagerInterface $em): Response
    {
        try {
            $em->remove($notification);
            $em->flush();
            return $this->json(['message' => 'Notification supprimée avec succès.']);
        } catch (\Exception $e) {
            return $this->json(['message' => 'Erreur lors de la suppression.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    #[Route(
        '/api/dynamic-query/{idInvite}/{idEntreprise}',
        name: 'app_dynamic_query',
        requirements: [
            'idEntreprise' => Requirement::DIGITS,
            'idInvite' => Requirement::DIGITS
        ],
        methods: ['POST']
    )]
    public function query(int $idInvite, int $idEntreprise, Request $request)
    {
        $requestData = json_decode($request->getContent(), true) ?? [];
        $reponseData = $this->searchService->search($requestData);
        $entityCanvas = $this->constante->getEntityCanvas(NotificationSinistre::class);
        $this->constante->loadCalculatedValue($entityCanvas, $reponseData["data"]);

        // 6. Rendre le template Twig avec les données filtrées et les informations de statut/pagination
        return $this->render('components/_list_content.html.twig', [
            'status' => $reponseData["status"], // Contient l'erreur ou les infos de pagination
            'totalItems' => $reponseData["totalItems"],  // Le nombre total d'éléments (pour la pagination)
            'data' => $reponseData["data"], // Les entités NotificationSinistre trouvées
            'entite_nom' => $this->getEntityName($this),
            'serverRootName' => $this->getServerRootName($this),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(NotificationSinistre::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new NotificationSinistre(), $idEntreprise),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($reponseData["data"]),
            'idEntreprise' => $idEntreprise,
            'idInvite' => $idInvite,
        ]);
    }

    /**
     * Retourne la liste des contacts pour une notification de sinistre donnée.
     */
    #[Route('/api/{id}/contacts/{usage}', name: 'api.get_contacts', methods: ['GET'])]
    public function getContactsListApi(int $id, ?string $usage = "generic"): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var NotificationSinistre $notification */
            $notification = $this->notificationSinistreRepository->find($id);
            if (!$notification) {
                throw $this->createNotFoundException("La notification de sinistre avec l'ID $id n'a pas été trouvée.");
            }
            $data = $notification->getContacts();
        }
        $entityCanvas = $this->constante->getEntityCanvas(Contact::class);
        $this->constante->loadCalculatedValue($entityCanvas, $data);

        return $this->render("components/_" . $usage . "_list_component.html.twig", [
            'data' => $data,
            'entite_nom' => $this->getEntityName(Contact::class),
            'serverRootName' => $this->getServerRootName(Contact::class),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(Contact::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Contact(), $this->getEntreprise()->getId()),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
            'idInvite' => $this->getInvite()->getId(),
            'idEntreprise' => $this->getEntreprise()->getId(),
            'parentEntityId' => $id,
            'parentFieldName' => 'notificationSinistre', // Le Contact est lié par le champ 'notificationSinistre'
            'customAddAction' => "click->collection#addItem", //Custom Action pour Ajouter à la collection
            // 'customEditAction' => "click->collection#editItem", //Custom Action pour Editer un élement de la collection
            // 'customDeleteAction' => "click->collection#deleteItem", //Custom Action pour Supprimer un élément de la collection
        ]);
    }


    #[Route('/api/{id}/pieces/{usage}', name: 'api.get_pieces', methods: ['GET'])]
    public function getPiecesListApi(int $id, ?string $usage = "generic"): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var NotificationSinistre $notification */
            $notification = $this->notificationSinistreRepository->find($id);
            if (!$notification) {
                throw $this->createNotFoundException("La notification de sinistre avec l'ID $id n'a pas été trouvée.");
            }
            $data = $notification->getPieces();
        }
        $pieceCanvas = $this->constante->getEntityCanvas(PieceSinistre::class);
        $this->constante->loadCalculatedValue($pieceCanvas, $data);

        return $this->render("components/_" . $usage . "_list_component.html.twig", [
            'data' => $data,
            'entite_nom' => $this->getEntityName(PieceSinistre::class),
            'serverRootName' => $this->getServerRootName(PieceSinistre::class),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(PieceSinistre::class),
            'entityCanvas' => $pieceCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new PieceSinistre(), $this->getEntreprise()->getId()),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
            'idInvite' => $this->getInvite()->getId(),
            'idEntreprise' => $this->getEntreprise()->getId(),
            'parentEntityId' => $id,
            'parentFieldName' => 'notificationSinistre', // La PieceSinistre est liée par le champ 'notificationSinistre'
            'customAddAction' => "click->collection#addItem", //Custom Action pour Ajouter à la collection
            // 'customEditAction' => "click->collection#editItem", //Custom Action pour Editer un élement de la collection
            // 'customDeleteAction' => "click->collection#deleteItem", //Custom Action pour Supprimer un élément de la collection
        ]);
    }


    #[Route('/api/{id}/taches/{usage}', name: 'api.get_taches', methods: ['GET'])]
    public function getTachesListApi(int $id, ?string $usage = "generic"): Response
    {
        $data = [];
        /** @var NotificationSinistre $notification */
        $notification = null;
        if ($id !== 0) {
            $notification = $this->notificationSinistreRepository->find($id);
            if (!$notification) {
                throw $this->createNotFoundException("La notification de sinistre avec l'ID $id n'a pas été trouvée.");
            }
            $data = $notification->getTaches();
        }
        $tacheCanvas = $this->constante->getEntityCanvas(Tache::class);
        $this->constante->loadCalculatedValue($tacheCanvas, $data);
        $entityFormCanvas = $this->constante->getEntityFormCanvas($notification, $this->getEntreprise()->getId());

        // --- NOUVEAU : Extraction des options de la collection ---
        $collectionOptions = [];
        // On parcourt le layout du formulaire pour trouver le widget de la collection de tâches.
        foreach ($entityFormCanvas['form_layout'] as $row) {
            foreach ($row['colonnes'] as $col) {
                foreach ($col['champs'] as $field) {
                    // On vérifie si le champ est un widget de collection et si son nom correspond à 'taches'.
                    if (is_array($field) && ($field['widget'] ?? null) === 'collection' && ($field['field_code'] ?? null) === 'taches') {
                        $collectionOptions = $field['options'];
                        break 3; // On a trouvé, on sort des trois boucles.
                    }
                }
            }
        }

        return $this->render("components/_" . $usage . "_list_component.html.twig", [
            'data' => $data,
            'entite_nom' => $this->getEntityName(Tache::class),
            'collectionOptions' => $collectionOptions, // On passe les options au template
            'listeCanvas' => $this->constante->getListeCanvas(Tache::class),
            'entityCanvas' => $tacheCanvas,
            'idInvite' => $this->getInvite()->getId(),
            'idEntreprise' => $this->getEntreprise()->getId(),
            'parentEntityId' => $id,
            'customAddAction' => "click->collection#addItem", //Custom Action pour Ajouter à la collection
            
            // 'serverRootName' => $this->getServerRootName(Tache::class),
            // 'constante' => $this->constante,
            // 'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Tache(), $this->getEntreprise()->getId()),
            // 'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
        ]);
    }


    #[Route('/api/{id}/offreIndemnisationSinistres/{usage}', name: 'api.get_offreIndemnisationSinistres', methods: ['GET'])]
    public function getOffresIndemnisationListApi(int $id, ?string $usage = "generic"): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var NotificationSinistre $notification */
            $notification = $this->notificationSinistreRepository->find($id);
            if (!$notification) {
                throw $this->createNotFoundException("La notification de sinistre avec l'ID $id n'a pas été trouvée.");
            }
            $data = $notification->getOffreIndemnisationSinistres();
        }
        $offreCanvas = $this->constante->getEntityCanvas(OffreIndemnisationSinistre::class);
        $this->constante->loadCalculatedValue($offreCanvas, $data);

        return $this->render("components/_" . $usage . "_list_component.html.twig", [
            'data' => $data,
            'entite_nom' => $this->getEntityName(OffreIndemnisationSinistre::class),
            'serverRootName' => $this->getServerRootName(OffreIndemnisationSinistre::class),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(OffreIndemnisationSinistre::class),
            'entityCanvas' => $offreCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new OffreIndemnisationSinistre(), $this->getEntreprise()->getId()),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
            'idInvite' => $this->getInvite()->getId(),
            'idEntreprise' => $this->getEntreprise()->getId(),
            'parentEntityId' => $id,
            'parentFieldName' => 'notificationSinistre', // L'OffreIndemnisationSinistre est liée par le champ 'notificationSinistre'
            'customAddAction' => "click->collection#addItem", //Custom Action pour Ajouter à la collection
        ]);
    }
}
