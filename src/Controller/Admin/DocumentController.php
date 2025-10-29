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
        // Utilise la méthode du trait pour construire dynamiquement la carte
        // en inspectant les relations ManyToOne de l'entité Document.
        return $this->buildParentAssociationMapFromEntity(Document::class);
    }

    protected function getCollectionMap(): array
    {
        // Un document n'a pas de sous-collections à exposer.
        return [];
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
    public function submitApi(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): Response
    {
        return $this->handleFormSubmission(
            $request,
            Document::class,
            DocumentType::class,
            $em,
            $serializer
        );
    }


    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Document $document, EntityManagerInterface $em): Response
    {
        return $this->handleDeleteApi($document, $em);
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
    public function query(Request $request)
    {
        return $this->renderViewOrListComponent(Document::class, $request, true);
    }
}
