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
use App\Service\Document\DocumentFichier;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\CanvasBuilder;
use App\Entity\OffreIndemnisationSinistre;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use App\Controller\Admin\ControllerUtilsTrait;
use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\Cotation;
use App\Entity\Partenaire;
use App\Entity\Piste;
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
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private DocumentRepository $documentRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService, // Ajoutez cette ligne
        private SerializerInterface $serializer,
        CanvasBuilder $canvasBuilder // Inject CanvasBuilder without property promotion
    ) {
        // Assign the injected CanvasBuilder to the property declared in the trait
        $this->canvasBuilder = $canvasBuilder;
    }

    /**
     * 3. IMPLÉMENTER LA "NOTICE D'INSTRUCTIONS" REQUISE PAR LE TRAIT
     * On déclare ici tous les parents possibles pour un Document.
     */
    protected function getParentAssociationMap(): array
    {
        // Utilise la méthode du trait pour construire dynamiquement la carte
        // en inspectant les relations ManyToOne de l'entité Document.
        return $this->buildParentAssociationMapFromEntity(Document::class);
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Document::class);
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
        return $this->renderViewOrListComponent(Document::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Document $document, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Document::class,
            DocumentType::class,
            $document
            // No specific initializer needed for a new Document
        );
    }


    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): Response
    {
        return $this->handleFormSubmission(
            $request,
            Document::class,
            DocumentType::class
        );
    }


    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Document $document): Response
    {
        return $this->handleDeleteApi($document);
    }

    /**
     * Télécharge le fichier d'un document.
     *
     * DEUX CORRECTIONS, ET ELLES COMPTENT TOUTES LES DEUX.
     *
     * 1. LE CONTRÔLE D'ACCÈS N'EXISTAIT PAS. La route se contentait du `ROLE_USER` de
     *    la classe : n'importe quel utilisateur connecté, de n'importe quelle
     *    entreprise, obtenait n'importe quel document en incrémentant l'identifiant
     *    dans l'URL. Le ParamConverter chargeait l'entité par sa clé primaire, sans
     *    jamais demander à qui elle appartenait. On repasse donc par le MÊME chemin que
     *    l'assistant : droit de lecture sur Document, et résolution scopée entreprise
     *    par le service de recherche. Un document d'ailleurs devient un 404 — la
     *    réponse qu'aurait donnée un id inexistant, et qui n'apprend donc rien.
     *
     * 2. LE FICHIER ARRIVAIT SOUS SON NOM DE STOCKAGE. `downloadObject()` sans quatrième
     *    argument sert le nom généré par SmartUniqueNamer : l'utilisateur recevait
     *    « contrat-a1b2c3d4.pdf » là où sa fiche affiche « Contrat KIN AVIA ». Le nom
     *    lisible vient maintenant de DocumentFichier, la même source que l'assistant :
     *    le fichier servi par la rubrique et celui servi par Ket sont identiques, nom
     *    compris.
     */
    #[Route('/api/{id}/download', name: 'api.download', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function downloadApi(
        int $id,
        DownloadHandler $downloadHandler,
        WorkspaceAccessResolver $accessResolver,
        DocumentFichier $documentFichier,
    ): Response {
        // getInvite() résout l'invité du WORKSPACE COURANT (connectedTo) et lève
        // lui-même si l'utilisateur n'en a aucun : le périmètre est celui de l'écran.
        $invite = $this->getInvite();
        $entreprise = $invite->getEntreprise();
        if (!$accessResolver->canRead($invite, 'Document')) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }

        $result = $this->searchService->search(Document::class, ['id' => $id], $entreprise, null, 1, 1);
        $document = $result['data'][0] ?? null;
        if (($result['status']['code'] ?? 500) !== 200 || !$document instanceof Document || $document->getNomFichierStocke() === null) {
            throw $this->createNotFoundException('Document introuvable ou sans fichier.');
        }

        return $downloadHandler->downloadObject(
            $document,
            DocumentFichier::CHAMP_VICH,
            null,
            $documentFichier->nomDeTelechargement($document),
        );
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
        return $this->renderViewOrListComponent(Document::class, $request, true);
    }
}
