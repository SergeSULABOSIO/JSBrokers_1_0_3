<?php

namespace App\Controller\Admin;

use App\Entity\ConditionPartage;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Form\ConditionPartageType;
use App\Repository\ConditionPartageRepository;
use App\Constantes\Constante;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use App\Services\CanvasBuilder;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/conditionpartage", name: 'admin.conditionpartage.')]
#[IsGranted('ROLE_USER')]
class ConditionPartageController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private InviteRepository $inviteRepository,
        private EntrepriseRepository $entrepriseRepository,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer,
        private Constante $constante,
        private ConditionPartageRepository $conditionPartageRepository,
        CanvasBuilder $canvasBuilder // Inject CanvasBuilder without property promotion
    ) {
        // Assign the injected CanvasBuilder to the property declared in the trait
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(ConditionPartage::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(ConditionPartage::class);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request)
    {
        return $this->renderViewOrListComponent(ConditionPartage::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?ConditionPartage $conditionPartage, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            ConditionPartage::class,
            ConditionPartageType::class,
            $conditionPartage
        );
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            ConditionPartage::class,
            ConditionPartageType::class
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(ConditionPartage $conditionPartage): Response
    {
        return $this->handleDeleteApi($conditionPartage);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request)
    {
        return $this->renderViewOrListComponent(ConditionPartage::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, Request $request, ?string $usage = "generic"): Response
    {
        // La requête est transmise pour que la collection puisse lire « ids » : en
        // création, un sélecteur garde ses choix côté navigateur et les redemande ici
        // pour que le SERVEUR les rende, avec le gabarit des lignes enregistrées.
        return $this->handleCollectionApiRequest($id, $collectionName, ConditionPartage::class, $usage, $request);
    }

    /**
     * LE CATALOGUE DES RISQUES, pour en désigner les cibles de cette condition.
     *
     * On CHOISIT ici un risque existant, on n'en fabrique pas un : créer depuis la fiche
     * d'une condition dupliquerait le catalogue de l'entreprise, une entrée « Incendie »
     * par condition. Symétrique du sélecteur de clients d'un portefeuille.
     */
    // PRIORITÉ : la route générique des collections (/api/{id}/{collectionName}/{usage})
    // capturerait « risque-picker » comme un nom de collection et répondrait 404. Une
    // priorité explicite vaut mieux qu'un ordre de déclaration, qu'un déplacement de
    // méthode suffirait à casser en silence.
    #[Route('/api/{id}/risque-picker', name: 'api.risque_picker', requirements: ['id' => Requirement::DIGITS], methods: ['GET'], priority: 10)]
    public function risquePicker(int $id): Response
    {
        if (!$this->mayAccessEntity(ConditionPartage::class, Invite::ACCESS_MODIFICATION)) {
            throw $this->createAccessDeniedException("Modification des conditions de partage hors de votre périmètre d'accès.");
        }
        $entreprise = $this->getEntreprise();
        if ($entreprise === null) {
            throw $this->createNotFoundException("Espace de travail introuvable.");
        }

        // ID 0 = LA CONDITION N'EXISTE PAS ENCORE. On sert quand même le catalogue :
        // en création, les risques choisis attendent dans le tampon du navigateur et
        // seront rattachés après l'enregistrement. Refuser ici rendrait la collection
        // inutilisable au moment précis où l'utilisateur en a besoin. Même tolérance
        // que findParentOrNew() pour les listes de collection.
        $conditionPartage = $id === 0
            ? new ConditionPartage()
            : $this->em->getRepository(ConditionPartage::class)->find($id);

        if ($conditionPartage === null
            || ($id !== 0 && $conditionPartage->getEntreprise()?->getId() !== $entreprise->getId())) {
            throw $this->createNotFoundException("Condition de partage introuvable dans cet espace de travail.");
        }

        $risques = $this->em->getRepository(Risque::class)->findBy(
            ['entreprise' => $entreprise],
            ['nomComplet' => 'ASC'],
        );

        return $this->render('components/conditionpartage/_risque_picker.html.twig', [
            'conditionPartage' => $conditionPartage,
            'risques' => $risques,
            'risquesTotal' => count($risques),
            'risquesCibles' => count($conditionPartage->getProduits()),
        ]);
    }

    /**
     * Cible un risque du catalogue — sans le retirer à personne.
     *
     * La relation est un ManyToMany : plusieurs conditions peuvent viser le même risque.
     * Sous l'ancienne cardinalité, ce geste l'aurait détaché de la condition précédente,
     * en silence et alors qu'il pilote des montants.
     */
    #[Route('/api/{id}/attach-risque/{risqueId}', name: 'api.attach_risque', requirements: ['id' => Requirement::DIGITS, 'risqueId' => Requirement::DIGITS], methods: ['PUT'])]
    public function attachRisque(ConditionPartage $conditionPartage, int $risqueId): JsonResponse
    {
        $risque = $this->risqueDuPerimetre($conditionPartage, $risqueId, $erreur);
        if ($risque === null) {
            return $erreur;
        }

        $conditionPartage->addProduit($risque);
        $this->em->flush();

        return $this->json(['message' => "Risque ajouté aux risques ciblés."]);
    }

    /**
     * Retire un risque des cibles — SANS le supprimer du catalogue, où il sert ailleurs
     * (pistes, sinistres, autres conditions). « Retirer » n'est pas « supprimer ».
     */
    #[Route('/api/{id}/detach-risque/{risqueId}', name: 'api.detach_risque', requirements: ['id' => Requirement::DIGITS, 'risqueId' => Requirement::DIGITS], methods: ['DELETE'])]
    public function detachRisque(ConditionPartage $conditionPartage, int $risqueId): JsonResponse
    {
        $risque = $this->risqueDuPerimetre($conditionPartage, $risqueId, $erreur);
        if ($risque === null) {
            return $erreur;
        }

        $conditionPartage->removeProduit($risque);
        $this->em->flush();

        return $this->json(['message' => "Risque retiré des risques ciblés."]);
    }

    /**
     * Le contrôle commun aux deux mutations : droit de modification, condition et risque
     * appartenant TOUS DEUX à l'espace de travail courant. Fail-closed.
     */
    private function risqueDuPerimetre(ConditionPartage $conditionPartage, int $risqueId, ?JsonResponse &$erreur): ?Risque
    {
        $erreur = null;

        if (!$this->mayAccessEntity(ConditionPartage::class, Invite::ACCESS_MODIFICATION)) {
            $erreur = $this->accessDeniedJson();

            return null;
        }

        $entreprise = $this->getEntreprise();
        if ($entreprise === null || $conditionPartage->getEntreprise()?->getId() !== $entreprise->getId()) {
            $erreur = $this->json(['message' => "Condition de partage introuvable dans cet espace de travail."], Response::HTTP_NOT_FOUND);

            return null;
        }

        $risque = $this->em->getRepository(Risque::class)->find($risqueId);
        if ($risque === null || $risque->getEntreprise()?->getId() !== $entreprise->getId()) {
            $erreur = $this->json(['message' => "Risque introuvable dans cet espace de travail."], Response::HTTP_NOT_FOUND);

            return null;
        }

        return $risque;
    }
}