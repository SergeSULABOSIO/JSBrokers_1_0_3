<?php

/**
 * @file Ce fichier contient le contrôleur PieceSinistreController.
 * @description Ce contrôleur est un CRUD complet pour l'entité `PieceSinistre`.
 * Il est responsable de :
 * 1. `index()`: Afficher la vue principale de la liste des pièces de sinistre.
 * 2. Fournir des points de terminaison API pour :
 *    - `getFormApi()`: Obtenir le formulaire de création/édition.
 *    - `submitApi()`: Traiter la soumission du formulaire, en gérant l'association à une `NotificationSinistre` parente grâce au `HandleChildAssociationTrait`.
 *    - `deleteApi()`: Supprimer une pièce.
 *    - `getDocumentsListApi()`: Charger la liste des documents liés à une pièce.
 */

namespace App\Controller\Admin;

use App\Entity\Invite;
use DateTimeImmutable;
use App\Entity\Entreprise;
use App\Constantes\Constante;
use App\Entity\PieceSinistre;
use App\Form\PieceSinistreType;
use App\Constantes\MenuActivator;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use App\Repository\PieceSinistreRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/piecesinistre", name: 'admin.piecesinistre.')]
#[IsGranted('ROLE_USER')]
class PieceSinistreController extends AbstractController
{
    use HandleChildAssociationTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private PieceSinistreRepository $pieceSinistreRepository,
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
        $data = $this->pieceSinistreRepository->findAll();
        $entityCanvas = $this->constante->getEntityCanvas(PieceSinistre::class);
        $this->constante->loadCalculatedValue($entityCanvas, $data);

        return $this->render('components/_view_manager.html.twig', [
            'data' => $data,
            'entite_nom' => $this->getEntityName($this),
            'serverRootName' => $this->getServerRootName($this),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(PieceSinistre::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new PieceSinistre(), $idEntreprise),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
            'idInvite' => $idInvite,
            'idEntreprise' => $idEntreprise,
        ]);
    }


    /**
     * Fournit le formulaire HTML pour une pièce.
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?PieceSinistre $piece, Request $request): Response
    {
        // MISSION 3 : Récupérer l'idEntreprise depuis la requête.
        $idEntreprise = $request->query->get('idEntreprise');
        $idInvite = $request->query->get('idInvite');

        if (!$idEntreprise) {
            $entreprise = $this->getEntreprise();
        } else {
            $entreprise = $this->entrepriseRepository->find($idEntreprise);
        }
        if (!$entreprise) throw $this->createNotFoundException("L'entreprise n'a pas été trouvée pour générer le formulaire.");

        if (!$idInvite) {
            $invite = $this->getInvite();
        } else {
            $invite = $this->inviteRepository->find($idInvite);
        }
        if (!$invite || $invite->getEntreprise()->getId() !== $entreprise->getId()) {
            throw $this->createAccessDeniedException("Vous n'avez pas les droits pour générer ce formulaire.");
        }

        if (!$piece) {
            $piece = new PieceSinistre();
            $piece->setInvite($invite);
        }

        $form = $this->createForm(PieceSinistreType::class, $piece);

        $entityCanvas = $this->constante->getEntityCanvas(PieceSinistre::class);
        $this->constante->loadCalculatedValue($entityCanvas, [$piece]);
        $entityFormCanvas = $this->constante->getEntityFormCanvas($piece, $entreprise->getId()); // On utilise l'ID de l'entreprise validée

        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $entityFormCanvas,
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

        /** @var PieceSinistre $piece */
        $piece = isset($data['id']) && $data['id'] ? $em->getRepository(PieceSinistre::class)->find($data['id']) : new PieceSinistre();

        $pieceId = $data['id'] ?? null;
        if (!$pieceId) {
            //Paramètres par défaut
            $piece->setReceivedAt(new DateTimeImmutable("now"));
            $piece->setInvite($this->getInvite());
        }

        $form = $this->createForm(PieceSinistreType::class, $piece);
        $form->submit($submittedData, false);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->associateParent($piece, $data, $em);
            $em->persist($piece);
            $em->flush();
            // On sérialise l'entité complète (avec son nouvel ID) pour la renvoyer
            $jsonEntity = $serializer->serialize($piece, 'json', ['groups' => 'list:read']);
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
    public function deleteApi(PieceSinistre $piece, EntityManagerInterface $em): Response
    {
        try {
            $em->remove($piece);
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
        $entityCanvas = $this->constante->getEntityCanvas(PieceSinistre::class);
        $this->constante->loadCalculatedValue($entityCanvas, $reponseData["data"]);

        // 6. Rendre le template Twig avec les données filtrées et les informations de statut/pagination
        return $this->render('components/_list_content.html.twig', [
            'status' => $reponseData["status"], // Contient l'erreur ou les infos de pagination
            'totalItems' => $reponseData["totalItems"],  // Le nombre total d'éléments (pour la pagination)
            'data' => $reponseData["data"], // Les entités NotificationSinistre trouvées
            'entite_nom' => $this->getEntityName($this),
            'serverRootName' => $this->getServerRootName($this),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(PieceSinistre::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new PieceSinistre(), $idEntreprise),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($reponseData["data"]),
            'idEntreprise' => $idEntreprise,
            'idInvite' => $idInvite,
        ]);
    }

    #[Route('/api/{id}/documents', name: 'api.get_documents', methods: ['GET'])]
    public function getDocumentsListApi(int $id, PieceSinistreRepository $repository): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var PieceSinistre $piece */
            $piece = $repository->find($id);
            if (!$piece) {
                throw $this->createNotFoundException("La pièce avec l'ID $id n'a pas été trouvée.");
            }
            $data = $piece->getDocuments();
        }
        $contactCanvas = $this->constante->getEntityCanvas(PieceSinistre::class);
        $this->constante->loadCalculatedValue($contactCanvas, $data);

        return $this->render('components/_generic_list_component.html.twig', [
            'data' => $data,
            'entite_nom' => $this->getEntityName(PieceSinistre::class),
            'serverRootName' => $this->getServerRootName(PieceSinistre::class),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(PieceSinistre::class),
            'entityCanvas' => $contactCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new PieceSinistre(), $this->getEntreprise()->getId()),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
            'idInvite' => $this->getInvite()->getId(),
            'idEntreprise' => $this->getEntreprise()->getId(),
            'customAddAction' => "click->collection#addItem", //Custom Action pour Ajouter à la collection
            // 'customEditAction' => "click->collection#editItem", //Custom Action pour Editer un élement de la collection
            // 'customDeleteAction' => "click->collection#deleteItem", //Custom Action pour Supprimer un élément de la collection
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
     * Déduit le nom de l'entité à partir du nom du contrôleur.
     * Exemple: PieceSinistreController -> PieceSinistre
     * @return string
     */
    private function getEntityName($objectOrClass): string
    {
        $shortClassName = (new \ReflectionClass($objectOrClass))->getShortName();
        return str_replace('Controller', '', $shortClassName);
    }

    /**
     * Déduit le nom racine du serveur à partir du nom du contrôleur.
     * Exemple: PieceSinistreController -> piecesinistre
     * @return string
     */
    private function getServerRootName($className): string
    {
        return strtolower($this->getEntityName($className));
    }
}
