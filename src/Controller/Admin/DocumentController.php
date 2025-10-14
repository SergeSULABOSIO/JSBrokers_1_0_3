<?php

/**
 * @file Ce fichier contient le contrôleur DocumentController.
 * @description Ce contrôleur est un CRUD complet pour l'entité `Document`.
 * Il est responsable de :
 * 1. `index()`: Afficher la vue principale de la liste des documents (page non-générique).
 * 2. Fournir des points de terminaison API pour :
 *    - `getFormApi()`: Obtenir le formulaire de création/édition.
 *    - `submitApi()`: Traiter la soumission du formulaire, en gérant l'association à diverses entités parentes
 *      (PieceSinistre, Tache, etc.) grâce au `HandleChildAssociationTrait`.
 *    - `deleteApi()`: Supprimer un document.
 *    - `downloadApi()`: Gérer le téléchargement du fichier associé à un document, en utilisant le `DownloadHandler` de VichUploader.
 *
 * Ce contrôleur est un bon exemple de gestion d'une entité "enfant" qui peut être liée à de nombreux
 * types d'entités "parentes" de manière dynamique.
 */

namespace App\Controller\Admin;

use App\Entity\Tache;
use App\Entity\Invite;
use App\Entity\Document;
use App\Entity\Feedback;
use App\Entity\Paiement;
use App\Entity\Entreprise;
use App\Form\DocumentType;
use App\Constantes\Constante;
use App\Entity\PieceSinistre;
use App\Constantes\MenuActivator;
use App\Repository\InviteRepository;
use App\Repository\DocumentRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use App\Entity\OffreIndemnisationSinistre;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Vich\UploaderBundle\Handler\DownloadHandler;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/document", name: 'admin.document.')]
#[IsGranted('ROLE_USER')]
class DocumentController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private DocumentRepository $documentRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService, // Ajoutez cette ligne
    ) {}

    /**
     * 3. IMPLÉMENTER LA "NOTICE D'INSTRUCTIONS" REQUISE PAR LE TRAIT
     * On déclare ici tous les parents possibles pour un Document.
     */
    protected function getParentAssociationMap(): array
    {
        return [
            // Clé envoyée par le client => Classe de l'entité parente
            'pieceSinistre' => PieceSinistre::class,
            'tache' => Tache::class,
            'offreIndemnisation' => OffreIndemnisationSinistre::class,
            'paiement' => Paiement::class,
            'feedback' => Feedback::class,
            // Si demain un Document peut être lié à une 'Facture',
            // il suffira d'ajouter 'facture' => Facture::class ici.
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
        $data = $this->documentRepository->findAll();
        $entityCanvas = $this->constante->getEntityCanvas(Document::class);
        $this->constante->loadCalculatedValue($entityCanvas, $data);

        return $this->render('components/_view_manager.html.twig', [
            'data' => $data,
            'entite_nom' => $this->getEntityName($this),
            'serverRootName' => $this->getServerRootName($this),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(Document::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Document(), $idEntreprise),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($data), // On passe le nouveau tableau de valeurs
            'idInvite' => $idInvite,
            'idEntreprise' => $idEntreprise,
        ]);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Document $document, Request $request): Response
    {
        ['entreprise' => $entreprise, 'invite' => $invite] = $this->validateWorkspaceAccess($request);
        $idEntreprise = $entreprise->getId();
        $idInvite = $invite->getId();

        if (!$document) {
            $document = new Document();
        }

        $form = $this->createForm(DocumentType::class, $document);

        $entityCanvas = $this->constante->getEntityCanvas(Document::class);
        $this->constante->loadCalculatedValue($entityCanvas, [$document]);
        $entityFormCanvas = $this->constante->getEntityFormCanvas($document, $entreprise->getId()); // On utilise l'ID de l'entreprise validée

        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $entityFormCanvas,
            'entityCanvas' => $entityCanvas,
            'idEntreprise' => $idEntreprise,
            'idInvite' => $idInvite,
        ]);
    }


    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): Response
    {
        $data = $request->request->all();
        $files = $request->files->all();
        $submittedData = array_merge($data, $files);

        $document = isset($data['id']) ? $em->getRepository(Document::class)->find($data['id']) : new Document();

        $form = $this->createForm(DocumentType::class, $document);
        $form->submit($submittedData, false);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->associateParent($document, $data, $em);
            $em->persist($document);
            $em->flush();

            $jsonEntity = $serializer->serialize($document, 'json', ['groups' => 'list:read']);
            return $this->json([
                'message' => 'Enregistrée avec succès!',
                'entity' => json_decode($jsonEntity) // On renvoie l'objet JSON
            ]);
        }

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[$error->getOrigin()->getName()][] = $error->getMessage();
        }
        return $this->json([
            'success' => false,
            'message' => 'Veuillez corriger les erreurs ci-dessous.',
            'errors'  => $errors // On envoie le tableau détaillé des erreurs au client
        ], 422); // 422 = Unprocessable Entity
    }


    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Document $document, EntityManagerInterface $em): Response
    {
        $em->remove($document);
        $em->flush();
        return $this->json(['message' => 'Document supprimé.']);
    }

    /**
     * NOUVELLE ACTION : Gère le téléchargement d'un fichier.
     */
    #[Route('/api/{id}/download', name: 'api.download', methods: ['GET'])]
    public function downloadApi(Document $document, DownloadHandler $downloadHandler): Response
    {
        // Le DownloadHandler de VichUploader s'occupe de tout :
        // il génère une réponse HTTP avec le bon fichier et les bons en-têtes.
        return $downloadHandler->downloadObject($document, $fileField = 'fichier');
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
        $entityCanvas = $this->constante->getEntityCanvas(Document::class);
        $this->constante->loadCalculatedValue($entityCanvas, $reponseData["data"]);

        // 6. Rendre le template Twig avec les données filtrées et les informations de statut/pagination
        return $this->render('components/_list_content.html.twig', [
            'status' => $reponseData["status"], // Contient l'erreur ou les infos de pagination
            'totalItems' => $reponseData["totalItems"],  // Le nombre total d'éléments (pour la pagination)
            'data' => $reponseData["data"], // Les entités NotificationSinistre trouvées
            'entite_nom' => $this->getEntityName($this),
            'serverRootName' => $this->getServerRootName($this),
            'constante' => $this->constante,
            'listeCanvas' => $this->constante->getListeCanvas(Document::class),
            'entityCanvas' => $entityCanvas,
            'entityFormCanvas' => $this->constante->getEntityFormCanvas(new Document(), $idEntreprise),
            'numericAttributes' => $this->constante->getNumericAttributesAndValuesForTotalsBar($reponseData["data"]),
            'idEntreprise' => $idEntreprise,
            'idInvite' => $idInvite,
        ]);
    }
}
