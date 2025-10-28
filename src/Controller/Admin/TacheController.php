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
use App\Constantes\Constante;
use App\Repository\TacheRepository;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
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
            'offreIndemnisationSinistre' => OffreIndemnisationSinistre::class,
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
        // Utilisation de la fonction réutilisable du trait
        return $this->renderViewManager(Tache::class, $idInvite, $idEntreprise);
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
        /** @var Tache $tache */
        $tache = $this->findParentOrNew(Tache::class, $id);
        $data = $tache->getFeedbacks();
        return $this->renderCollectionOrList($usage, Feedback::class, $tache, $id, $data, 'feedbacks');
    }

    // AJOUTEZ CETTE NOUVELLE ACTION
    #[Route('/api/{id}/documents/{usage}', name: 'api.get_documents', methods: ['GET'])]
    public function getDocumentsListApi(int $id, ?string $usage = "generic"): Response
    {
        /** @var Tache $tache */
        $tache = $this->findParentOrNew(Tache::class, $id);
        $data = $tache->getDocuments();
        return $this->renderCollectionOrList($usage, Document::class, $tache, $id, $data, 'documents');
    }
}
