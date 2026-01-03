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
use App\Entity\Document;
use App\Services\ServiceMonnaies;
use App\Entity\NotificationSinistre;
use App\Entity\PieceSinistre;
use App\Repository\ContactRepository;
use App\Repository\TacheRepository;
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
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        private SerializerInterface $serializer
    ) {}

    protected function getCollectionMap(): array
    {
        // Utilise la méthode du trait pour construire dynamiquement la carte.
        return $this->buildCollectionMapFromEntity(NotificationSinistre::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(NotificationSinistre::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
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
                $notification->setOccuredAt(new DateTimeImmutable("now"));
                $notification->setNotifiedAt(new DateTimeImmutable("now"));
                $notification->setInvite($invite);
                $notification->setDescriptionDeFait("RAS");
            }
        );
    }


    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        return $this->handleFormSubmission(
            $request,
            NotificationSinistre::class,
            NotificationSinistreType::class
        );
    }

    /**
     * Supprime une pièce.
     */
    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(NotificationSinistre $notification): Response
    {
        return $this->handleDeleteApi($notification);
    }


    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        // La logique de recherche et de rendu JSON est maintenant centralisée dans le trait.
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
    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, NotificationSinistre::class, $usage);
    }

    /**
     * NOUVEAU : Point de terminaison générique pour récupérer les détails d'une entité.
     * Cette méthode est cruciale pour ouvrir des entités liées depuis le volet de visualisation.
     *
     * @param string $entityType Le nom simple de l'entité (ex: "tache", "contact").
     * @param integer $id L'ID de l'entité à charger.
     * @return JsonResponse
     */
    #[Route('/api/get-entity-details/{entityType}/{id}', name: 'api.get_entity_details', methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]
    public function getEntityDetailsApi(string $entityType, int $id): JsonResponse
    {
        // La logique de récupération est déjà dans le trait `ControllerUtilsTrait`.
        // Cette méthode gère la recherche de l'entité par son nom court, la récupération
        // de son canevas d'affichage et le calcul des valeurs dérivées.
        // C'est une approche plus générique et DRY que le switch/case précédent.
        $details = $this->getEntityDetailsForType($entityType, $id);

        // Le trait a déjà fait tout le travail, il ne reste qu'à renvoyer la réponse JSON.
        // Le groupe de sérialisation 'default' est utilisé pour correspondre à ce que le
        // reste de l'application utilise, assurant la cohérence des données.
        return $this->json($details, 200, [], ['groups' => 'default']);
    }
}
