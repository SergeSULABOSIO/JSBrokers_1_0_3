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
use App\Entity\Invite;
use DateTimeImmutable;
use App\Entity\Contact;
use App\Constantes\Constante;
use App\Entity\PieceSinistre;
use App\Entity\Document;
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

    /**
     * @var array<string, string>
     * Table de correspondance entre le nom de la collection dans l'URL et la classe de l'entité.
     */
    private const COLLECTION_MAP = [
        'contacts' => Contact::class,
        'pieces' => PieceSinistre::class,
        'taches' => Tache::class,
        'offreIndemnisationSinistres' => OffreIndemnisationSinistre::class,
    ];

    protected function getCollectionMap(): array
    {
        return self::COLLECTION_MAP;
    }

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
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(NotificationSinistre::class, $request);
    }


    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?NotificationSinistre $notification, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            NotificationSinistre::class,
            NotificationSinistreType::class,
            $notification,
            function (NotificationSinistre $notification, Invite $invite) {
                // Custom initializer for a new NotificationSinistre
                $notification->setNotifiedAt(new DateTimeImmutable("now"));
                $notification->setInvite($invite);
            }
        );
    }


    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            NotificationSinistre::class,
            NotificationSinistreType::class,
            $em,
            $serializer,
            function (NotificationSinistre $notification, array $data) {
                if (!$notification->getId()) {
                    $notification->setOccuredAt(new DateTimeImmutable("now"));
                    $notification->setNotifiedAt(new DateTimeImmutable("now"));
                    $notification->setCreatedAt(new DateTimeImmutable("now"));
                    $notification->setInvite($this->getInvite());
                    $notification->setDescriptionDeFait("RAS");
                }
                $notification->setUpdatedAt(new DateTimeImmutable("now"));
            }
        );
    }

    /**
     * Supprime une pièce.
     */
    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(NotificationSinistre $notification, EntityManagerInterface $em): Response
    {
        return $this->handleDeleteApi($notification, $em);
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
        return $this->renderViewOrListComponent(NotificationSinistre::class, $request, true);
    }


    /**
     * Action générique pour retourner la liste d'une collection liée à une NotificationSinistre.
     * Fusionne les anciennes méthodes getContactsListApi, getPiecesListApi, etc.
     *
     * @param int $id L'ID de la NotificationSinistre parente.
     * @param string $collectionName Le nom de la collection (ex: 'contacts', 'pieces').
     * @param string|null $usage Le contexte d'affichage ('generic' ou 'dialog').
     * @return Response
     */
    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, NotificationSinistre::class, $usage);
    }
}
