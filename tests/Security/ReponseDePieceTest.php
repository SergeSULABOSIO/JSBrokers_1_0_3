<?php

namespace App\Tests\Security;

use App\Service\Document\ReponseDePiece;
use PHPUnit\Framework\TestCase;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * UN FICHIER DÉPOSÉ NE S'OUVRE JAMAIS COMME UNE PAGE DE JOSEARA (chantier 18, Serge
 * 2026-10-22) : un .html ou un .svg porteur de script, affiché depuis joseara.com, lirait la
 * session du cabinet. Toutes les routes qui servent un fichier passent par ReponseDePiece.
 */
final class ReponseDePieceTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir() . '/reponse-de-piece-' . bin2hex(random_bytes(4));
        mkdir($this->dossier);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dossier . '/*') ?: []);
        rmdir($this->dossier);
    }

    private function fichier(string $nom, string $contenu): string
    {
        file_put_contents($chemin = $this->dossier . '/' . $nom, $contenu);

        return $chemin;
    }

    private function service(): ReponseDePiece
    {
        return new ReponseDePiece($this->createStub(StorageInterface::class));
    }

    /** @return iterable<string, array{string, string}> */
    public static function fichiersActifs(): iterable
    {
        yield 'html' => ['piege.html', '<!doctype html><html><body><script>alert(document.cookie)</script></body></html>'];
        yield 'svg' => ['piege.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'];
    }

    /** @dataProvider fichiersActifs */
    public function testUnFichierActifEstToujoursTelechargeJamaisAffiche(string $nom, string $contenu): void
    {
        // Même quand l'appelant DEMANDE l'affichage : le type réel le refuse.
        $reponse = $this->service()->servir($this->fichier($nom, $contenu), $nom, afficher: true);

        self::assertStringStartsWith('attachment', (string) $reponse->headers->get('Content-Disposition'));
        self::assertStringNotContainsString('html', (string) $reponse->headers->get('Content-Type'));
        self::assertStringNotContainsString('svg', (string) $reponse->headers->get('Content-Type'));
        self::assertSame('nosniff', $reponse->headers->get('X-Content-Type-Options'));
        self::assertSame('sandbox', $reponse->headers->get('Content-Security-Policy'));
    }

    public function testUnTypeDeLaListeBlancheSAfficheSeulementSiOnLeDemande(): void
    {
        // Un PNG minimal (1×1).
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $chemin = $this->fichier('image.png', $png);

        $affichee = $this->service()->servir($chemin, 'image.png', afficher: true);
        self::assertStringStartsWith('inline', (string) $affichee->headers->get('Content-Disposition'));
        self::assertSame('image/png', $affichee->headers->get('Content-Type'));
        self::assertSame('sandbox', $affichee->headers->get('Content-Security-Policy'));

        $parDefaut = $this->service()->servir($chemin, 'image.png');
        self::assertStringStartsWith('attachment', (string) $parDefaut->headers->get('Content-Disposition'), 'par défaut : téléchargement');
    }

    public function testUnHtmlDeguiseEnPdfNEstPasAffiche(): void
    {
        // L'extension ment : c'est le CONTENU qui décide.
        $reponse = $this->service()->servir($this->fichier('facture.pdf', '<html><script>alert(1)</script></html>'), 'facture.pdf', afficher: true);

        self::assertStringStartsWith('attachment', (string) $reponse->headers->get('Content-Disposition'));
    }

    public function testUnNomPiegeNInjecteRien(): void
    {
        $nom = "rapport\"\r\nSet-Cookie: x=1\x00\x07/..\\fin.pdf";
        $disposition = ReponseDePiece::disposition('attachment', $nom);

        self::assertDoesNotMatchRegularExpression('/[\r\n\x00-\x1F\x7F]/', $disposition, 'aucun caractère de contrôle');
        self::assertSame(2, substr_count($disposition, '"'), 'seulement les guillemets du repli');
        self::assertStringNotContainsString('/', str_replace("UTF-8''", '', $disposition));
        self::assertStringNotContainsString('\\', $disposition);
    }

    public function testUnNomAccentueEstEncodeSelonLaRfc5987(): void
    {
        $disposition = ReponseDePiece::disposition('attachment', 'Relevé de compte.pdf');

        self::assertStringContainsString("filename*=utf-8''Relev%C3%A9%20de%20compte.pdf", $disposition);
        self::assertMatchesRegularExpression('/filename="[\x20-\x7E]+"/', $disposition, 'repli ASCII pour les navigateurs anciens');
    }

    public function testUnNomVideDevientDocument(): void
    {
        self::assertSame('document', ReponseDePiece::nomPropre("\r\n\" "));
    }
}
