<?php

/**
 * @file Ce fichier contient le contrôleur PieceSinistreController.
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

use DateTimeImmutable;
use App\Entity\Document;
use App\Constantes\Constante;
use App\Entity\PieceSinistre;
use App\Form\PieceSinistreType;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use App\Repository\PieceSinistreRepository;
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

#[Route("/admin/piecesinistre", name: 'admin.piecesinistre.')]
#[IsGranted('ROLE_USER')]
class PieceSinistreController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

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
        // Utilisation de la fonction réutilisable du trait pour assurer que toutes
        // les variables nécessaires, y compris 'serverRootName', sont passées.
        return $this->renderViewManager(PieceSinistre::class, $idInvite, $idEntreprise);
    }


    /**
     * Fournit le formulaire HTML pour une pièce.
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?PieceSinistre $piece, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            PieceSinistre::class,
            PieceSinistreType::class,
            $piece,
            function (PieceSinistre $piece, \App\Entity\Invite $invite) {
                // Custom initializer for a new PieceSinistre
                $piece->setInvite($invite);
            }
        );
    }

    /**
     * Traite la soumission du formulaire.
     */
    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): Response
    {
        return $this->handleFormSubmission(
            $request,
            PieceSinistre::class,
            PieceSinistreType::class,
            $em,
            $serializer,
            function (PieceSinistre $piece) {
                if (!$piece->getId()) {
                    $piece->setReceivedAt(new DateTimeImmutable("now"));
                    // L'initialiseur de renderFormCanvas s'en occupe déjà
                    // $piece->setInvite($this->getInvite());
                }
            }
        );
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

    #[Route('/api/{id}/documents/{usage}', name: 'api.get_documents', methods: ['GET'])]
    public function getDocumentsListApi(int $id, ?string $usage = "generic"): Response
    {
        $data = [];
        if ($id !== 0) {
            /** @var PieceSinistre $piece */
            $piece = $this->pieceSinistreRepository->find($id);
            if (!$piece) {
                throw $this->createNotFoundException("La pièce sinistre avec l'ID $id n'a pas été trouvée.");
            }
            $data = $piece->getDocuments();
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
            'parentEntityId' => $id,
            'parentFieldName' => 'pieceSinistre',
            'customAddAction' => "click->collection#addItem", //Custom Action pour Ajouter à la collection
        ]);
    }
}
