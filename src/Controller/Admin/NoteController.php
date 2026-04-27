<?php

namespace App\Controller\Admin;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Entity\Note;
use App\Entity\Invite;
use App\Constantes\Constante;
use App\Form\NoteType;
use App\Repository\NoteRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Services\CanvasBuilder;
use App\Services\ServiceMonnaies;
use App\Services\Canvas\CalculationProvider;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\JSBDynamicSearchService;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Admin\ControllerUtilsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Traits\HandleChildAssociationTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route("/admin/note", name: 'admin.note.')]
#[IsGranted('ROLE_USER')]
class NoteController extends AbstractController
{
    use HandleChildAssociationTrait;
    use ControllerUtilsTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private NoteRepository $noteRepository,
        private Constante $constante,
        private JSBDynamicSearchService $searchService,
        private SerializerInterface $serializer, // Ajout de SerializerInterface
        private CalculationProvider $calculationProvider,
        private ServiceMonnaies $serviceMonnaies, // NOUVEAU : Injection du service des monnaies
        CanvasBuilder $canvasBuilder
    ) {
        // Assign the injected CanvasBuilder to the property declared in the trait
        $this->canvasBuilder = $canvasBuilder;
    }

    protected function getCollectionMap(): array
    {
        return $this->buildCollectionMapFromEntity(Note::class);
    }

    protected function getParentAssociationMap(): array
    {
        return $this->buildParentAssociationMapFromEntity(Note::class);
    }

    #[Route('/test', name: 'test')]
    public function edit(): Response
    {
        return $this->render('components/note/editor.html.twig', []);
    }

    #[Route('/index/{idInvite}/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        return $this->renderViewOrListComponent(Note::class, $request);
    }

    #[Route('/api/get-form/{id?}', name: 'api.get_form', methods: ['GET'])]
    public function getFormApi(?Note $note, Request $request): Response
    {
        return $this->renderFormCanvas(
            $request,
            Note::class,
            NoteType::class,
            $note,
            function (Note $note, Invite $invite) {
                $note->setSignature((string)time());
                $note->setReference("N" . time());
                $note->setType(Note::TYPE_NOTE_DE_DEBIT);
                $note->setInvite($invite);
                $note->setAddressedTo(Note::TO_ASSUREUR);
                $note->setValidated(false);
                
                // NOUVEAU : Initialisation de la date du jour au premier chargement
                $note->setSentAt(new \DateTimeImmutable());
            }
        );
    }

    #[Route('/api/get-preview-url/{id}', name: 'api.get_preview_url', methods: ['GET'])]
    public function getPreviewUrlApi(Note $note, Request $request): JsonResponse
    {
        // NOUVEAU : On vérifie si on doit télécharger directement ou juste afficher/imprimer.
        if ($request->query->get('download')) {
            // Si le paramètre 'download' est présent, on génère l'URL de téléchargement PDF.
            $finalUrl = $this->generateUrl('admin.note.download_pdf', ['id' => $note->getId()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
        } else {
            // Sinon, on génère l'URL de l'aperçu, en conservant les autres paramètres (comme 'print').
            $params = ['id' => $note->getId()];
            if ($request->query->get('print')) {
                $params['print'] = 1;
            }
            $finalUrl = $this->generateUrl('admin.note.show_preview', $params, \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return $this->json(['previewUrl' => $finalUrl]);
    }

    #[Route('/apercu/{id}', name: 'show_preview', methods: ['GET'])]
    public function showPreview(Note $note): Response
    {
        // CORRECTION : On charge les valeurs calculées pour la note ET pour chaque article.
        
        // 1. On charge les valeurs de la note (montantTotal, montantTaxe, etc.)
        $this->canvasBuilder->loadAllCalculatedValues($note);

        // 2. On charge les valeurs pour chaque article (montantArticleHT, valeurUnitaireHT, etc.)
        foreach ($note->getArticles() as $article) {
            $this->canvasBuilder->loadAllCalculatedValues($article);
        }

        // 3. On récupère l'entreprise et les autres données nécessaires pour le template.
        $entreprise = $this->getEntreprise();
        $entityCanvas = $this->canvasBuilder->getEntityCanvas(Note::class);

        return $this->render('admin/note/note_preview.html.twig', [
            'note' => $note,
            'entreprise' => $entreprise,
            'entityCanvas' => $entityCanvas,
            // On passe la monnaie d'affichage pour l'utiliser dans le template
            'monnaie' => $this->serviceMonnaies->getCodeMonnaieAffichage()
        ]);
    }

    #[Route('/download-pdf/{id}', name: 'download_pdf', methods: ['GET'])]
    public function downloadPdf(Note $note): Response
    {
        // 1. On configure DomPDF pour qu'il respecte les styles d'impression (@media print)
        $pdfOptions = new Options();
        $pdfOptions->set('defaultFont', 'Arial');
        $pdfOptions->set('isHtml5ParserEnabled', true);
        // NOUVEAU : Autorise DomPDF à télécharger des contenus externes (CSS, images).
        // C'est la clé pour que le style Bootstrap soit appliqué.
        $pdfOptions->set('isRemoteEnabled', true);
        // La ligne la plus importante : elle demande à DomPDF de simuler le rendu d'impression
        // NOUVEAU : Améliore la compatibilité avec les CSS modernes (Flexbox, etc.)
        $pdfOptions->set('chroot', $this->getParameter('kernel.project_dir') . '/public');


        $pdfOptions->setDefaultMediaType('print');

        $dompdf = new Dompdf($pdfOptions);

        // 2. On récupère le contexte nécessaire pour le template, comme dans showPreview
        $this->canvasBuilder->loadAllCalculatedValues($note);
        foreach ($note->getArticles() as $article) {
            $this->canvasBuilder->loadAllCalculatedValues($article);
        }
        $entreprise = $this->getEntreprise();
        $entityCanvas = $this->canvasBuilder->getEntityCanvas(Note::class);
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();

        // NOUVEAU : On prépare les chemins absolus pour les images
        $logoPath = null;
        if ($entreprise && $entreprise->getThumbnail()) {
            $logoPath = $this->getParameter('kernel.project_dir') . '/public/images/entreprises/' . $entreprise->getThumbnail();
        } else {
            $logoPath = $this->getParameter('kernel.project_dir') . '/public/images/entreprises/logofav.png';
        }

        // 3. On génère le HTML en utilisant la méthode renderView
        $html = $this->renderView('admin/note/note_preview.html.twig', [
            'note' => $note,
            'entreprise' => $entreprise,
            'entityCanvas' => $entityCanvas,
            'monnaie' => $monnaie,
            // On passe le chemin absolu du logo au template
            'logo_path_for_pdf' => $logoPath,
        ]);

        // 4. On charge le HTML dans DomPDF, on génère le PDF et on le propose au téléchargement
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="note-' . $note->getReference() . '.pdf"',
        ]);
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        // NOUVEAU : On récupère l'invité connecté de manière sécurisée côté serveur
        $inviteConnecte = $this->getInvite();

        return $this->handleFormSubmission(
            $request,
            Note::class,
            NoteType::class,
            function (Note $note) use ($inviteConnecte) {
                // S'il s'agit d'une nouvelle note
                if (!$note->getId()) {
                    if (!$note->getReference()) {
                        $note->setReference("N" . time());
                    }
                    if (!$note->getSignature()) {
                        $note->setSignature((string)time());
                    }
                    if ($note->isValidated() === null) {
                        $note->setValidated(false);
                    }
                    
                    // NOUVEAU : On s'assure que la note est bien liée à l'utilisateur (et donc à l'entreprise) pour ne pas être orpheline
                    $note->setInvite($inviteConnecte);
                    
                    // NOUVEAU : Sécurité supplémentaire si la date n'est pas passée dans le POST
                    if (!$note->getSentAt()) {
                        $note->setSentAt(new \DateTimeImmutable());
                    }
                }
            }
        );
    }

    #[Route('/api/delete/{id}', name: 'api.delete', methods: ['DELETE'])]
    public function deleteApi(Note $note): Response
    {
        return $this->handleDeleteApi($note);
    }

    #[Route('/api/dynamic-query/{idInvite}/{idEntreprise}', name: 'app_dynamic_query', requirements: ['idEntreprise' => Requirement::DIGITS, 'idInvite' => Requirement::DIGITS], methods: ['POST'])]
    public function query(Request $request): Response
    {
        return $this->renderViewOrListComponent(Note::class, $request, true);
    }

    #[Route('/api/{id}/{collectionName}/{usage}', name: 'api.get_collection', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function getCollectionListApi(int $id, string $collectionName, ?string $usage = "generic"): Response
    {
        return $this->handleCollectionApiRequest($id, $collectionName, Note::class, $usage);
    }
}