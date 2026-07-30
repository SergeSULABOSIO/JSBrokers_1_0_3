<?php

namespace App\Ai\Export;

use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use Dompdf\Dompdf;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Environment;

/**
 * @file Export d'UN message de l'assistant en document.
 * @description Trois transports, UN SEUL rendu HTML : `corpsHtml()` produit le
 * fragment que consomment le PDF, le Word et l'e-mail. C'est le nœud DRY du
 * lot — toute mise en forme ajoutée profite aux trois d'un coup.
 *
 * Le fragment porte des styles INLINE (et non des classes) : les clients mail
 * ne suivent pas les feuilles de style, comme le documente déjà
 * templates/emails/_layout.html.twig. DomPDF s'en accommode parfaitement, donc
 * c'est le plus petit dénominateur commun qui rend le partage possible.
 *
 * Formats :
 *  - markdown : le contenu stocké, octet pour octet (aucun rendu) ;
 *  - pdf      : DomPDF, pattern canonique de TokenInvoicePdfService ;
 *  - word     : le même fragment enveloppé dans les namespaces Office, servi en
 *               application/msword. Zéro dépendance ; substituable par un vrai
 *               .docx derrière cette signature sans toucher aux appelants.
 *
 * L'export IMAGE ne passe pas par ici : la fidélité d'un graphique Chart.js
 * exige de capturer la bulle réelle dans le navigateur
 * (assets/controllers/assistant-message-image.js).
 */
class MessageExporter
{
    public const FORMAT_PDF = 'pdf';
    public const FORMAT_WORD = 'word';
    public const FORMAT_MARKDOWN = 'markdown';

    /** Formats servis par la route d'export — miroir de FORMATS_SERVEUR (JS). */
    public const FORMATS = [self::FORMAT_PDF, self::FORMAT_WORD, self::FORMAT_MARKDOWN];

    private const MIMES = [
        self::FORMAT_PDF => 'application/pdf',
        self::FORMAT_WORD => 'application/msword',
        self::FORMAT_MARKDOWN => 'text/markdown; charset=UTF-8',
    ];

    private const EXTENSIONS = [
        self::FORMAT_PDF => 'pdf',
        self::FORMAT_WORD => 'doc',
        self::FORMAT_MARKDOWN => 'md',
    ];

    public function __construct(
        private MessageMarkdownParser $parser,
        private Environment $twig,
        private ParameterBagInterface $params,
    ) {
    }

    /**
     * Fragment HTML du corps du message — SOURCE UNIQUE du PDF, du Word et de
     * l'e-mail. Le parseur ne renvoie que du texte brut ; le HTML naît ici,
     * sous l'autoescape de Twig.
     */
    public function corpsHtml(AssistantMessage $message): string
    {
        return $this->twig->render('admin/assistant_ia/export/_message_corps.html.twig', [
            'blocs' => $this->parser->analyser((string) $message->getContenu()),
        ]);
    }

    /**
     * @param string $format une valeur de self::FORMATS
     *
     * @throws \InvalidArgumentException format inconnu (la route filtre déjà en amont)
     */
    public function exporter(
        AssistantMessage $message,
        string $format,
        Entreprise $entreprise,
        string $assistantNom,
    ): MessageExportFichier {
        if (!in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException(sprintf('Format d\'export inconnu : %s', $format));
        }

        $nomFichier = $this->nomFichier($message, self::EXTENSIONS[$format]);
        $mime = self::MIMES[$format];

        // Le Markdown EST le stockage : aucun rendu, aucune « normalisation ».
        // Ce court-circuit garantit que l'export rend exactement ce que la base
        // contient (assertSame dans AssistantMessageExportTest).
        if ($format === self::FORMAT_MARKDOWN) {
            return new MessageExportFichier((string) $message->getContenu(), $nomFichier, $mime);
        }

        $contexte = [
            'corpsHtml' => $this->corpsHtml($message),
            'message' => $message,
            'entreprise' => $entreprise,
            'assistantNom' => $assistantNom,
            'estAssistant' => $message->getRole() === AssistantMessage::ROLE_ASSISTANT,
            'logo' => $this->logoDataUri(),
        ];

        if ($format === self::FORMAT_WORD) {
            $html = $this->twig->render('admin/assistant_ia/export/message_word.html.twig', $contexte);

            return new MessageExportFichier($html, $nomFichier, $mime);
        }

        return new MessageExportFichier(
            $this->pdf($this->twig->render('admin/assistant_ia/export/message_pdf.html.twig', $contexte)),
            $nomFichier,
            $mime
        );
    }

    /** Bytes du PDF — pattern canonique du projet (TokenInvoicePdfService). */
    private function pdf(string $html): string
    {
        $dompdf = new Dompdf();
        $dompdf->getOptions()->setChroot($this->params->get('kernel.project_dir') . '/public');
        $dompdf->getOptions()->setIsRemoteEnabled(false);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** Logo JS Brokers en data URI (embarqué : DomPDF est en mode non-distant). */
    private function logoDataUri(): ?string
    {
        $path = $this->params->get('kernel.project_dir') . '/public/images/entreprises/logofav.png';
        if (!is_file($path)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
    }

    /**
     * Nom de fichier volontairement SANS donnée utilisateur (ni titre de
     * conversation, ni nom de client) : rien à assainir, donc aucune faille
     * d'en-tête Content-Disposition, et aucun secret dans un nom de fichier qui
     * survivra dans le dossier Téléchargements.
     */
    private function nomFichier(AssistantMessage $message, string $extension): string
    {
        return sprintf(
            'message-ia-%d-%s.%s',
            (int) $message->getId(),
            ($message->getCreatedAt() ?? new \DateTimeImmutable())->format('Ymd'),
            $extension
        );
    }
}
