<?php

namespace App\Ai\Document\Renderer;

use App\Ai\Document\DocumentFormat;
use App\Ai\Document\PiedDePage;
use App\Ai\Document\RapportSpec;
use App\Ai\Document\ThemeDocument;
use Dompdf\Dompdf;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Rapport en PDF — pattern canonique du projet (TokenInvoicePdfService,
 * MessageExporter) : DomPDF, chroot sur public/, mode distant coupé, A4 portrait.
 *
 * Réutilise le gabarit et le logo de {@see HtmlRapportRenderer} : deux formats, une
 * seule mise en page. Ce qui s'y ajoute ici est ce qu'un PDF seul sait faire — la
 * pagination et le pied de page répété.
 */
final class PdfRapportRenderer implements RapportRendererInterface
{
    public function __construct(
        private readonly HtmlRapportRenderer $html,
        private readonly ParameterBagInterface $params,
    ) {
    }

    public function format(): DocumentFormat
    {
        return DocumentFormat::Pdf;
    }

    public function rendre(RapportSpec $spec, array $sections, PiedDePage $pied, ThemeDocument $theme): string
    {
        $corps = $this->html->corps($spec, $sections, $pied, $theme);
        $palette = $theme->palette();

        // Pied de page RÉPÉTÉ et numérotation : c'est la valeur propre du PDF.
        // `position: fixed` sur une page @page est ce que DomPDF sait rendre sur
        // chaque feuille, contrairement à un simple bloc en fin de flux.
        //
        // `@page { background }` EN PLUS du fond de <body> : la zone de marge d'une
        // feuille A4 n'appartient pas au corps, et un thème sombre y laisserait
        // sinon un liseré blanc sur chaque page.
        $style = '<style>'
            . '@page { margin: 18mm 14mm 22mm 14mm; background: ' . $palette['fond'] . '; }'
            . 'body { margin:0; background: ' . $palette['fond'] . '; }'
            . '.jsb-pied { position: fixed; bottom: -14mm; left: 0; right: 0; '
            . 'font-family:\'DejaVu Sans\',Arial,sans-serif; font-size:7pt; color:' . $palette['gris'] . '; '
            . 'border-top:1px solid ' . $palette['filet'] . '; padding-top:4px; }'
            . '.jsb-pied .jsb-page:after { content: counter(page) " / " counter(pages); }'
            . '</style>';

        $piedFixe = '<div class="jsb-pied">'
            . htmlspecialchars($pied->ligne(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ' — page <span class="jsb-page"></span></div>';

        $document = $this->html->document($style . $piedFixe . $corps, $spec->titre, $theme);

        $dompdf = new Dompdf();
        $dompdf->getOptions()->setChroot($this->params->get('kernel.project_dir') . '/public');
        $dompdf->getOptions()->setIsRemoteEnabled(false);
        $dompdf->loadHtml($document);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
