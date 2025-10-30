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
use App\Constantes\Constante;
use App\Repository\TacheRepository;
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
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private TacheRepository $tacheRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService, // Ajoutez cette ligne
    ) {}

    protected function getCollectionMap(): array
    {
        // Utilise la méthode du trait pour construire dynamiquement la carte.
        return $this->buildCollectionMapFromEntity(Tache::class);
    }


    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Tache::class);
    }


    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(Tache::class, $request);
    }

    

    /**
     * Fournit le formulaire HTML pour une pièce.
     */
    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Tache $tache, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Tache::class,
            TacheType::class,
            $tache,
            function (Tache $tache, Invite $invite) {
                // Custom initializer for a new Tache
                $tache->setClosed(false);
                $tache->setToBeEndedAt(new DateTimeImmutable("+1 days"));
                $tache->setExecutor($invite);
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
            Tache::class,
            TacheType::class,
            $em,
            $serializer,
            function (Tache $tache) {
                // Le trait TimestampableTrait gère createdAt et updatedAt
                // automatiquement grâce à l'annotation #[ORM\HasLifecycleCallbacks]
                // et aux méthodes dans le trait.
                // Il n'est donc plus nécessaire de les définir manuellement ici.
            }
        );
    }



    /**
     * Supprime une pièce.
     */
    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Tache $tache, EntityManagerInterface $em): Response
    {
        return $this->handleDeleteApi($tache, $em);
    }



    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request)
    {
        return $this->renderViewOrListComponent(Tache::class, $request, true);
    }


    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Tache::class, $usage);
    }
}
