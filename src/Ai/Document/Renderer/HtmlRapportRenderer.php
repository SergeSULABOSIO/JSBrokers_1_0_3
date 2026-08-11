<?php

namespace App\Ai\Document\Renderer;

use App\Ai\Document\DocumentFormat;
use App\Ai\Document\PiedDePage;
use App\Ai\Document\RapportSpec;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Environment;

/**
 * Rapport en PAGE WEB autonome : un seul fichier .html, styles inline, logo
 * embarqué en data-URI, aucune ressource externe. On peut le glisser dans un
 * courriel, l'ouvrir hors ligne, l'imprimer.
 *
 * Partage son gabarit avec le PDF ({@see PdfRapportRenderer}) : la mise en page
 * d'un document officiel n'a pas à dépendre du format demandé.
 */
final class HtmlRapportRenderer implements RapportRendererInterface
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ParameterBagInterface $params,
    ) {
    }

    public function format(): DocumentFormat
    {
        return DocumentFormat::Html;
    }

    public function rendre(RapportSpec $spec, array $sections, PiedDePage $pied): string
    {
        return $this->document($this->corps($spec, $sections, $pied), $spec->titre);
    }

    /** Le fragment commun, rendu sous l'autoescape de Twig. */
    public function corps(RapportSpec $spec, array $sections, PiedDePage $pied): string
    {
        return $this->twig->render('admin/assistant_ia/document/rapport.html.twig', [
            'spec'     => $spec,
            'sections' => $sections,
            'pied'     => $pied,
            'logo'     => $this->logoDataUri(),
        ]);
    }

    /** Enveloppe HTML complète — `lang` et `charset` compris : c'est un livrable. */
    public function document(string $corps, string $titre): string
    {
        return "<!DOCTYPE html>\n"
            . '<html lang="fr"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($titre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>'
            . '</head><body style="margin:0; background:#ffffff;">' . $corps . '</body></html>';
    }

    /**
     * Logo en data-URI : DomPDF tourne en mode non-distant et un .html téléchargé
     * n'a aucun accès au serveur. L'embarquer est la seule façon qu'il survive.
     */
    public function logoDataUri(): ?string
    {
        $chemin = $this->params->get('kernel.project_dir') . '/public/images/entreprises/logofav.png';
        if (!is_file($chemin)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($chemin));
    }
}
