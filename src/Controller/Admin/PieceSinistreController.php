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
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private PieceSinistreRepository $pieceSinistreRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService, // Ajoutez cette ligne
    ) {}

    protected function getCollectionMap(): array
    {
        // Utilise la méthode du trait pour construire dynamiquement la carte.
        return $this->buildCollectionMapFromEntity(PieceSinistre::class);
    }


    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(PieceSinistre::class);
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
    public function index(Request $request)
    {
        // Utilisation de la fonction réutilisable du trait pour assurer que toutes
        // les variables nécessaires, y compris 'serverRootName', sont passées.
        return $this->renderViewOrListComponent(PieceSinistre::class, $request);
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
        return $this->handleDeleteApi($piece, $em);
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
    public function query(Request $request)
    {
        return $this->renderViewOrListComponent(PieceSinistre::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, PieceSinistre::class, $usage);
    }
}
