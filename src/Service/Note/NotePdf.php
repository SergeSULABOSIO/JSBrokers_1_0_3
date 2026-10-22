<?php

namespace App\Service\Note;

use App\Entity\Note;
use App\Services\BordereauAnalysisPdfService;
use App\Services\CanvasBuilder;
use App\Services\ServiceMonnaies;
use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * LE PDF D'UNE NOTE — un seul rendu, pour le téléchargement et pour l'archive comptable
 * du cabinet (conservation dix ans, règle OHADA).
 *
 * Le cabinet et la monnaie sont ceux de la NOTE, jamais ceux de l'utilisateur connecté :
 * l'archive se construit aussi depuis la console, hors de tout cabinet ouvert.
 */
final class NotePdf
{
    public function __construct(
        private readonly CanvasBuilder $canvasBuilder,
        private readonly Environment $twig,
        private readonly ServiceMonnaies $serviceMonnaies,
        private readonly BordereauAnalysisPdfService $bordereauPdfService,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        // Les logos déposés ont leur dossier (chantier 18) ; les images de marque restent dans public/images.
        #[Autowire('%app.dossier_des_logos%')] private readonly string $dossierDesLogos,
    ) {
    }

    public function rendre(Note $note): string
    {
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isHtml5ParserEnabled', true);
        // AUCUN accès distant : le gabarit incorpore son CSS. Une requête sortante par PDF
        // ferait dépendre le rendu d'un CDN, et ouvrirait la lecture de ressources
        // arbitraires à qui contrôlerait le contenu d'une note.
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', $this->projectDir . '/public');
        $options->setDefaultMediaType('print');
        $dompdf = new Dompdf($options);

        $this->canvasBuilder->loadAllCalculatedValues($note);
        foreach ($note->getArticles() as $article) {
            $this->canvasBuilder->loadAllCalculatedValues($article);
        }
        $entreprise = $note->getEntreprise();
        // La monnaie de travail du cabinet de la NOTE — jamais celle de l'utilisateur
        // connecté (la console, ou un autre cabinet ouvert). Un cabinet sans monnaie, cas
        // anormal, ne fait pas échouer le rendu (et l'archive avec lui) : USD, la monnaie
        // de référence de la plateforme.
        $monnaie = ($entreprise !== null ? $this->serviceMonnaies->getMonnaieAffichagePourEntreprise($entreprise)?->getCode() : null) ?? 'USD';
        $logo = $entreprise?->getThumbnail()
            ? $this->dossierDesLogos . '/' . $entreprise->getThumbnail()
            : $this->projectDir . '/public/images/entreprises/logofav.png';

        $dompdf->loadHtml($this->twig->render('admin/note/note_preview.html.twig', [
            'note' => $note,
            'entreprise' => $entreprise,
            'entityCanvas' => $this->canvasBuilder->getEntityCanvas(Note::class),
            'monnaie' => $monnaie,
            'logo_path_for_pdf' => $logo,
        ]));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf = $dompdf->output();

        // Une note de bordereau : les lignes conformes du bordereau en annexe, en paysage.
        $bordereau = $note->getBordereau();
        if ($bordereau !== null) {
            $annexe = $this->bordereauPdfService->generatePdfString($bordereau, matchOnly: true);
            if ($annexe !== null) {
                $pdf = $this->fusionner($pdf, $annexe);
            }
        }

        return $pdf;
    }

    /** Le nom du fichier : la référence, sans ce qui casserait un en-tête ou un chemin. */
    public static function nomDeFichier(Note $note): string
    {
        return 'note-' . str_replace(['/', '\\'], '-', (string) $note->getReference()) . '.pdf';
    }

    private function fusionner(string $notePdf, string $annexe): string
    {
        $fpdi = new Fpdi('P', 'mm', 'A4');
        $fpdi->setPrintHeader(false);
        $fpdi->setPrintFooter(false);

        foreach ([$notePdf, $annexe] as $source) {
            $pages = $fpdi->setSourceFile(StreamReader::createByString($source));
            for ($i = 1; $i <= $pages; $i++) {
                $modele = $fpdi->importPage($i);
                $taille = $fpdi->getTemplateSize($modele);
                $fpdi->AddPage($taille['width'] > $taille['height'] ? 'L' : 'P', [$taille['width'], $taille['height']]);
                $fpdi->useTemplate($modele, 0, 0, $taille['width'], $taille['height'], true);
            }
        }

        return $fpdi->Output('', 'S');
    }
}
