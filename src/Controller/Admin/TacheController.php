<?php

/**
 * @file Ce fichier contient le contrôleur TacheController.
 * @description Ce contrôleur est un CRUD complet pour l'entité `Tache`.
 * Il est responsable de :
 * 1. `index()`: Afficher la vue principale de la liste des tâches (page non-générique).
 * 2. Fournir des points de terminaison API pour :
 *    - `getFormApi()`: Obtenir le formulaire de création/édition.
 *    - `submitApi()`: Traiter la soumission du formulaire, en gérant l'association à des entités parentes (ex: NotificationSinistre).
 *    - `deleteApi()`: Supprimer une tâche.
 *    - `getFeedbacksListApi()`, `getDocumentsListApi()`: Charger les listes des collections liées à une tâche.
 */

namespace App\Controller\Admin;

use App\Entity\Tache;
use App\Entity\Invite;
use DateTimeImmutable;
use App\Form\TacheType;
use App\Entity\Document;
use App\Entity\Feedback;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Repository\TacheRepository;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/tache", name: 'admin.tache.')]
#[IsGranted('ROLE_USER')]
class TacheController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private TacheRepository $tacheRepository,
        private Constante $constante,
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
        methods: ['GET', 'POST'])
    ]
    public function index(int $idInvite, int $idEntreprise)
    {
        $data = $this->tacheRepository->findAll();
        $entityCanvas = $this->constante->getEntityCanvas(Tache::class);
        $this->constante->loadCalculatedValue($entityCanvas, $data);

        return $this->render('components/_view_manager.html.twig', [
            'data' => $data,
            'entite_nom' => $this->getEntityName($this),
            'serverRootName' => $this->getServerRootName($this),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(Tache::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Tache(), $idEntreprise),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
            'idInvite' => $idInvite,
            'idEntreprise' => $idEntreprise,
        ]);
    }


    /**
     * Fournit le formulaire HTML pour une pièce.
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Tache $tache, Request $request): Response
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->validateWorkspaceAccess($request);
        $idEntreprise = $entreprise->getId();
        $idInvite = $invite->getId();

        if (!$tache) {
            $tache = new Tache();
            $tache->setClosed(false);
            $tache->setToBeEndedAt(new DateTimeImmutable("+1 days"));
            $tache->setExecutor($invite);
        }

        $form = $this->createForm(TacheType::class, $tache);

        $entityCanvas = $this->constante->getEntityCanvas(Tache::class);
        $this->constante->loadCalculatedValue($entityCanvas, [$tache]);
        $entityFormCanvas = $this->constante->getEntityFormCanvas($tache, $entreprise->getId());

        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $entityFormCanvas, // ID entreprise à adapter
            'entityCanvas' => $entityCanvas,
            'idEntreprise' => $idEntreprise,
            'idInvite' => $idInvite,
        ]);
    }

    /**
     * Traite la soumission du formulaire.
     */
    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): Response
    {
        $data = $request->request->all();
        $files = $request->files->all();
        $submittedData = array_merge($data, $files);

        /** @var Tache $tache */
        $tache = isset($data['id']) ? $em->getRepository(Tache::class)->find($data['id']) : new Tache();

        $tacheId = $data['id'] ?? null;
        if ($tacheId) {
            //Paramètres par défaut
            $tache->setUpdatedAt(new DateTimeImmutable("now"));
        }else{
            $tache->setCreatedAt(new DateTimeImmutable("now"));
        }

        $form = $this->createForm(TacheType::class, $tache);
        $form->submit($submittedData, false);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->associateParent($tache, $data, $em);
            $em->persist($tache);
            $em->flush();
            // On sérialise l'entité complète (avec son nouvel ID) pour la renvoyer
            $jsonEntity = $serializer->serialize($tache, 'json', ['groups' => 'list:read']);
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
    public function deleteApi(Tache $tache, EntityManagerInterface $em): Response
    {
        try {
            $em->remove($tache);
            $em->flush();
            return $this->json(['message' => 'Pièce supprimée avec succès.']);
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
        $entityCanvas = $this->constante->getEntityCanvas(Tache::class);
        $this->constante->loadCalculatedValue($entityCanvas, $reponseData["data"]);

        // 6. Rendre le template Twig avec les données filtrées et les informations de statut/pagination
        return $this->render('components/_list_content.html.twig', [
            'status' => $reponseData["status"], // Contient l'erreur ou les infos de pagination
            'totalItems' => $reponseData["totalItems"],  // Le nombre total d'éléments (pour la pagination)
            'data' => $reponseData["data"], // Les entités NotificationSinistre trouvées
            'entite_nom' => $this->getEntityName($this),
            'serverRootName' => $this->getServerRootName($this),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(Tache::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Tache(), $idEntreprise),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($reponseData["data"]),
            'idEntreprise' => $idEntreprise,
            'idInvite' => $idInvite,
        ]);
    }


    #[Route('/api/{id}/feedbacks/{usage}', name: 'api.get_feedbacks', methods: ['GET'])]
    public function getFeedbacksListApi(int $id, ?string $usage = "generic"): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var Tache $tache */
            $tache = $this->tacheRepository->find($id);
            if (!$tache) {
                throw $this->createNotFoundException("La tâche avec l'ID $id n'a pas été trouvée.");
            }
            $data = $tache->getFeedbacks();
        }
        $entityCanvas = $this->constante->getEntityCanvas(Feedback::class);
        $this->constante->loadCalculatedValue($entityCanvas, $data);

        return $this->render("components/_" . $usage . "_list_component.html.twig", [
            'data' => $data,
            'entite_nom' => $this->getEntityName(Feedback::class),
            'serverRootName' => $this->getServerRootName(Feedback::class),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(Feedback::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Feedback(), $this->getEntreprise()->getId()),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
            'idInvite' => $this->getInvite()->getId(),
            'idEntreprise' => $this->getEntreprise()->getId(),
            'customAddAction' => "click->collection#addItem", //Custom Action pour Ajouter à la collection
            // 'customEditAction' => "click->collection#editItem", //Custom Action pour Editer un élement de la collection
            // 'customDeleteAction' => "click->collection#deleteItem", //Custom Action pour Supprimer un élément de la collection
        ]);
    }

    // AJOUTEZ CETTE NOUVELLE ACTION
    #[Route('/api/{id}/documents/{usage}', name: 'api.get_documents', methods: ['GET'])]
    public function getDocumentsListApi(int $id, ?string $usage = "generic"): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var Tache $tache */
            $tache = $this->tacheRepository->find($id);
            if (!$tache) {
                throw $this->createNotFoundException("L'objet avec l'ID $id n'a pas été trouvée.");
            }
            $data = $tache->getDocuments();
        }
        $entityCanvas = $this->constante->getEntityCanvas(Document::class);
        $this->constante->loadCalculatedValue($entityCanvas, $data);

        return $this->render("components/_" . $usage . "_list_component.html.twig", [
            'data' => $data,
            'entite_nom' => $this->getEntityName(Document::class),
            'serverRootName' => $this->getServerRootName(Document::class),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(Document::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Document(), $this->getEntreprise()->getId()),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
            'idInvite' => $this->getInvite()->getId(),
            'idEntreprise' => $this->getEntreprise()->getId(),
            'customAddAction' => "click->collection#addItem", //Custom Action pour Ajouter à la collection
            // 'customEditAction' => "click->collection#editItem", //Custom Action pour Editer un élement de la collection
            // 'customDeleteAction' => "click->collection#deleteItem", //Custom Action pour Supprimer un élément de la collection
        ]);
    }
}
