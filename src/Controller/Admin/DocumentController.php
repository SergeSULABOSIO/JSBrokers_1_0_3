<?php

namespace App\Controller\Admin;

use App\Entity\Tache;
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
use App\Entity\OffreIndemnisationSinistre;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
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
    
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $manager,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private DocumentRepository $documentRepository,
        private Constante $constante,
    ) {}

    
    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        return $this->render('admin/document/index.html.twig', [
            'pageName' => $this->translator->trans("document_page_name_new"),
            'utilisateur' => $this->getUser(),
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'documents' => $this->documentRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
        ]);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Document $document, Constante $constante): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var Entreprise $entreprise */
        $entreprise = $invite->getEntreprise();

        if (!$document) {
            $document = new Document();
        }
        $form = $this->createForm(DocumentType::class, $document);
        return $this->render('components/_form_canvas.html.twig', [
            'form' => $form->createView(),
            'entityFormCanvas' => $constante->getEntityFormCanvas($document, $entreprise->getId())
        ]);
    }


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
        return $this->json(['message' => 'Erreurs de validation', 'errors' => $errors], 422);
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
}
