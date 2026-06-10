<?php

namespace App\Controller\Admin;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Entity\Bordereau;
use App\Entity\Note;
use App\Entity\Invite;
use App\Constantes\Constante;
use App\Form\NoteType;
use App\Repository\NoteRepository;
use App\Repository\InviteRepository;
use App\Repository\EntrepriseRepository;
use App\Services\BordereauAnalysisPdfService;
use App\Services\CanvasBuilder;
use App\Services\ServiceMonnaies;
use App\Services\Canvas\CalculationProvider;
use setasign\Fpdi\Tcpdf\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
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
        private BordereauAnalysisPdfService $bordereauPdfService,
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
            function (Note $note, Invite $invite) use ($request) {
                $note->setSignature((string)time());
                $note->setType(Note::TYPE_NOTE_DE_DEBIT);
                $note->setInvite($invite);
                $note->setAddressedTo(Note::TO_ASSUREUR);
                $note->setValidated(false);
                $note->setSentAt(new \DateTimeImmutable());

                // Pré-remplissage depuis un bordereau parent (parentContext du dialog-instance)
                $bordereauId = $request->query->get('parent_id');
                $parentField = $request->query->get('parent_field_name');

                if ($parentField === 'bordereau' && $bordereauId) {
                    $bordereau = $this->em->find(Bordereau::class, (int)$bordereauId);
                    if ($bordereau) {
                        $note->setBordereau($bordereau);
                        $note->setAssureur($bordereau->getAssureur());
                        $note->setNom('Commission — Bordereau ' . $bordereau->getReference());
                        $note->setReference('FACT-' . $bordereau->getReference());
                        return; // référence déjà définie, on sort
                    }
                }

                $note->setReference('N' . time());
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

    #[Route('/workspace-apercu/{id}', name: 'workspace_content', methods: ['GET'])]
    public function showWorkspaceContent(Note $note): JsonResponse
    {
        $this->canvasBuilder->loadAllCalculatedValues($note);
        foreach ($note->getArticles() as $article) {
            $this->canvasBuilder->loadAllCalculatedValues($article);
        }
        $entreprise = $this->getEntreprise();
        $entityCanvas = $this->canvasBuilder->getEntityCanvas(Note::class);

        $html = $this->renderView('admin/note/note_preview_workspace.html.twig', [
            'note'         => $note,
            'entreprise'   => $entreprise,
            'entityCanvas' => $entityCanvas,
            'monnaie'      => $this->serviceMonnaies->getCodeMonnaieAffichage(),
            'previewUrl'   => $this->generateUrl('admin.note.show_preview', ['id' => $note->getId()]),
        ]);

        return $this->json([
            'html'  => $html,
            'title' => $note->getTypeString() . ' — ' . $note->getReference(),
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
        $notePdfString = $dompdf->output();

        // 5. Si la note est liée à un bordereau, on annexe les lignes conformes en paysage
        $bordereau = $note->getBordereau();
        if ($bordereau !== null) {
            $bordereauPdfString = $this->bordereauPdfService->generatePdfString($bordereau, matchOnly: true);
            if ($bordereauPdfString !== null) {
                $notePdfString = $this->mergePdfs($notePdfString, $bordereauPdfString);
            }
        }

        return new Response($notePdfString, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="note-' . $note->getReference() . '.pdf"',
        ]);
    }

    private function mergePdfs(string $notePdf, string $bordereauPdf): string
    {
        $fpdi = new Fpdi('P', 'mm', 'A4');
        $fpdi->setPrintHeader(false);
        $fpdi->setPrintFooter(false);

        $count = $fpdi->setSourceFile(StreamReader::createByString($notePdf));
        for ($i = 1; $i <= $count; $i++) {
            $tpl  = $fpdi->importPage($i);
            $size = $fpdi->getTemplateSize($tpl);
            $fpdi->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
            $fpdi->useTemplate($tpl, 0, 0, $size['width'], $size['height'], true);
        }

        $count = $fpdi->setSourceFile(StreamReader::createByString($bordereauPdf));
        for ($i = 1; $i <= $count; $i++) {
            $tpl  = $fpdi->importPage($i);
            $size = $fpdi->getTemplateSize($tpl);
            $fpdi->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
            $fpdi->useTemplate($tpl, 0, 0, $size['width'], $size['height'], true);
        }

        return $fpdi->Output('', 'S');
    }

    #[Route('/api/submit', name: 'api.submit', methods: ['POST'])]
    public function submitApi(Request $request): JsonResponse
    {
        $inviteConnecte = $this->getInvite();

        return $this->handleFormSubmission(
            $request,
            Note::class,
            NoteType::class,
            function (Note $note) use ($inviteConnecte, $request) {
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
                    $note->setInvite($inviteConnecte);
                    if (!$note->getSentAt()) {
                        $note->setSentAt(new \DateTimeImmutable());
                    }

                    // The 'bordereau' field is suppressed from the form layout (to avoid Twig double-render),
                    // so the form binding sets it to null. Restore it from the parentContext sent by dialog-instance.
                    if ($note->getBordereau() === null) {
                        $bordereauId = $request->request->get('parent_id');
                        $parentField = $request->request->get('parent_field_name');
                        if ($parentField === 'bordereau' && $bordereauId) {
                            $bordereau = $this->em->find(Bordereau::class, (int)$bordereauId);
                            if ($bordereau) {
                                $note->setBordereau($bordereau);
                            }
                        }
                    }

                    // Bordereau → passe au statut "Facturé" dans la même transaction
                    if ($note->getBordereau() !== null) {
                        $bordereau = $note->getBordereau();
                        $bordereau->setCurrentAnalysisStep(Bordereau::STEP_NOTE_EMISE);
                        $bordereau->setUpdatedAt(new \DateTimeImmutable());
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