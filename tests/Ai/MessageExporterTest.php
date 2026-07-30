<?php

namespace App\Tests\Ai;

use App\Ai\Export\MessageExportFichier;
use App\Ai\Export\MessageExporter;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Rendu documentaire d'un message, SANS base de données : les entités sont
 * construites en mémoire, seuls Twig et DomPDF sont réellement sollicités.
 *
 * Ce test verrouille les deux propriétés qui font tenir l'architecture :
 *  - un SEUL fragment HTML nourrit PDF, Word et e-mail (DRY) ;
 *  - ce fragment échappe tout, quel que soit le contenu du message (sécurité).
 */
class MessageExporterTest extends KernelTestCase
{
    private MessageExporter $exporter;
    private Entreprise $entreprise;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->exporter = self::getContainer()->get(MessageExporter::class);

        $this->entreprise = (new Entreprise())->setNom('PHPUnit EXPORT SARL');
    }

    private function message(string $contenu, string $role = AssistantMessage::ROLE_ASSISTANT): AssistantMessage
    {
        return (new AssistantMessage())->setRole($role)->setContenu($contenu);
    }

    private function exporter(string $contenu, string $format): MessageExportFichier
    {
        return $this->exporter->exporter($this->message($contenu), $format, $this->entreprise, 'Ket');
    }

    // ──────────────────────────────────────────────────────── markdown ───

    public function testMarkdownRendLeContenuStockeOctetPourOctet(): void
    {
        // Le Markdown EST le stockage : aucun rendu, aucune « normalisation ».
        // Cette égalité stricte est le garde-fou contre tout nettoyage ajouté.
        $contenu = "## Titre\n\n- item **gras**\n\n```chart\n{\"labels\":[\"A\"]}\n```\n";
        $fichier = $this->exporter($contenu, MessageExporter::FORMAT_MARKDOWN);

        self::assertSame($contenu, $fichier->contenu);
        self::assertSame('text/markdown; charset=UTF-8', $fichier->mime);
        self::assertStringEndsWith('.md', $fichier->nomFichier);
    }

    // ───────────────────────────────────────────────────────────── pdf ───

    public function testPdfEstProduitEtCommenceParLaSignatureAttendue(): void
    {
        $fichier = $this->exporter('Bonjour, voici votre situation.', MessageExporter::FORMAT_PDF);

        self::assertStringStartsWith('%PDF-', $fichier->contenu);
        self::assertSame('application/pdf', $fichier->mime);
        self::assertStringEndsWith('.pdf', $fichier->nomFichier);
    }

    public function testPdfSupporteUnMessageRicheSansException(): void
    {
        // Non-régression DomPDF sur la combinaison la plus lourde que Ket émet.
        $contenu = <<<'MD'
            ## Situation

            | Client | Prime | Statut |
            |---|---:|:--:|
            | SONAS | 1 250,00 | [Payée](#success) |

            - Point **important**
            - Second point

            ```chart
            {"type":"bar","titre":"CA 2026","unite":"USD","labels":["Jan","Fév"],"series":[{"label":"HT","data":[1200,900]}],"legende":"Commissions encaissées."}
            ```
            MD;

        $fichier = $this->exporter($contenu, MessageExporter::FORMAT_PDF);

        self::assertStringStartsWith('%PDF-', $fichier->contenu);
        self::assertGreaterThan(2000, strlen($fichier->contenu));
    }

    // ──────────────────────────────────────────────────────────── word ───

    public function testWordPorteLesNamespacesOffice(): void
    {
        $fichier = $this->exporter('Bonjour.', MessageExporter::FORMAT_WORD);

        self::assertStringContainsString('xmlns:w', $fichier->contenu);
        self::assertStringContainsString('w:WordDocument', $fichier->contenu);
        self::assertSame('application/msword', $fichier->mime);
        self::assertStringEndsWith('.doc', $fichier->nomFichier);
    }

    // ───────────────────────────────────── un seul rendu, trois transports ───

    public function testLeMemeFragmentNourritLePdfEtLeWord(): void
    {
        $contenu = "Une **alerte** et un tableau :\n\n| A | B |\n|---|---|\n| 1 | 2 |";
        $message = $this->message($contenu);

        $fragment = $this->exporter->corpsHtml($message);
        $word = $this->exporter->exporter($message, MessageExporter::FORMAT_WORD, $this->entreprise, 'Ket');

        // Le Word contient le fragment TEL QUEL : preuve qu'il n'existe pas un
        // second chemin de rendu quelque part.
        self::assertStringContainsString(trim($fragment), $word->contenu);
        self::assertStringContainsString('<strong', $fragment);
        self::assertStringContainsString('<table', $fragment);
    }

    public function testLeFragmentPorteDesStylesInlineEtAucuneClasse(): void
    {
        // Les clients mail ne suivent pas les feuilles de style : une classe CSS
        // casserait l'e-mail sans que rien ne le signale.
        $fragment = $this->exporter->corpsHtml($this->message("## T\n\n- a\n\n| A |\n|---|\n| 1 |"));

        self::assertStringContainsString('style="', $fragment);
        self::assertStringNotContainsString('class="', $fragment);
    }

    public function testGraphiqueDevientUnTableauLisibleAvecSaLegende(): void
    {
        $contenu = "```chart\n" . json_encode([
            'titre' => 'CA encaissé 2026',
            'unite' => 'USD',
            'labels' => ['Jan', 'Fév'],
            'series' => [['label' => 'HT', 'data' => [1200, 900]]],
            'legende' => 'Commissions encaissées HT par mois.',
        ]) . "\n```";

        $fragment = $this->exporter->corpsHtml($this->message($contenu));

        self::assertStringContainsString('Graphique — CA encaissé 2026', $fragment);
        self::assertStringContainsString('HT (USD)', $fragment);
        self::assertStringContainsString('1 200,00', $fragment);
        self::assertStringContainsString('Commissions encaissées HT par mois.', $fragment);
    }

    // ─────────────────────────────────────────────────────────── sûreté ───

    public function testLeHtmlContenuDansUnMessageEstEchappe(): void
    {
        $contenu = "Alerte <script>alert(1)</script> et <img src=x onerror=\"alert(2)\">\n\n"
            . "| <b>Gras</b> |\n|---|\n| <div onclick=\"x\">c</div> |\n\n"
            . '- <svg onload="alert(3)"></svg>';

        $fragment = $this->exporter->corpsHtml($this->message($contenu));

        // Le texte reste visible pour le lecteur…
        self::assertStringContainsString('alert(1)', $fragment);
        self::assertStringContainsString('&lt;script&gt;', $fragment);

        // …mais aucune balise du message n'a survécu COMME balise.
        self::assertStringNotContainsString('<script', $fragment);
        self::assertStringNotContainsString('<img', $fragment);
        self::assertStringNotContainsString('<svg', $fragment);
        self::assertStringNotContainsString('<div', $fragment);
        self::assertStringNotContainsString('<b>', $fragment);

        // Les gestionnaires d'événements ne sont plus des ATTRIBUTS : leurs
        // guillemets sont échappés (onerror=&quot;), donc ils restent du texte
        // dans un <p>. C'est cette forme vivante-là qui doit être absente.
        foreach (['onerror="', "onerror='", 'onload="', "onload='", 'onclick="', "onclick='"] as $attributVivant) {
            self::assertStringNotContainsString($attributVivant, $fragment);
        }
    }

    public function testMessageVideProduitUnDocumentLisible(): void
    {
        $fragment = $this->exporter->corpsHtml($this->message(''));

        self::assertStringContainsString('aucun texte', $fragment);
        self::assertStringStartsWith('%PDF-', $this->exporter('', MessageExporter::FORMAT_PDF)->contenu);
    }

    // ──────────────────────────────────────────────────────── contrats ───

    public function testFormatInconnuEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->exporter('Bonjour.', 'xlsx');
    }

    public function testPieceJointeExposeLaFormeAttendueParCorporateMailer(): void
    {
        $piece = $this->exporter('Bonjour.', MessageExporter::FORMAT_PDF)->pieceJointe();

        self::assertSame(['content', 'filename', 'mime'], array_keys($piece));
        self::assertStringStartsWith('%PDF-', $piece['content']);
    }

    public function testLeNomDeFichierNeContientAucuneDonneeUtilisateur(): void
    {
        // Rien à assainir ⇒ aucune faille d'en-tête Content-Disposition.
        $nom = $this->exporter('Client ULTRA-CONFIDENTIEL "; rm -rf /', MessageExporter::FORMAT_PDF)->nomFichier;

        self::assertMatchesRegularExpression('/^message-ia-\d+-\d{8}\.pdf$/', $nom);
    }
}
