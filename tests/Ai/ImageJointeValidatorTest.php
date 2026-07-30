<?php

namespace App\Tests\Ai;

use App\Ai\Export\ImageJointeValidator;
use PHPUnit\Framework\TestCase;

/**
 * L'image jointe à un e-mail est le SEUL binaire que l'application accepte du
 * navigateur — parce qu'aucun rendu serveur ne sait rasteriser un graphique
 * Chart.js. Ces tests verrouillent la contrepartie : le flux reçu n'est jamais
 * réexpédié tel quel, il est reconnu par son contenu, borné, puis RECONSTRUIT à
 * partir de ses seuls pixels.
 */
class ImageJointeValidatorTest extends TestCase
{
    private ImageJointeValidator $validator;

    protected function setUp(): void
    {
        if (!\function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD requis.');
        }
        $this->validator = new ImageJointeValidator();
    }

    /** PNG minimal réel, produit par GD (jamais un octet écrit à la main). */
    private function png(int $largeur = 40, int $hauteur = 20): string
    {
        $image = imagecreatetruecolor($largeur, $hauteur);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 71, 171)); // cobalt
        ob_start();
        imagepng($image);
        $binaire = (string) ob_get_clean();
        imagedestroy($image);

        return $binaire;
    }

    public function testPngValideEstAccepteEtNomme(): void
    {
        $fichier = $this->validator->valider(base64_encode($this->png()), 42);

        self::assertSame('image/png', $fichier->mime);
        self::assertMatchesRegularExpression('/^message-ia-42-\d{8}\.png$/', $fichier->nomFichier);
        self::assertSame(IMAGETYPE_PNG, getimagesizefromstring($fichier->contenu)[2]);
    }

    public function testPrefixeDataUriEstAccepte(): void
    {
        // Le navigateur produit naturellement une data URL.
        $fichier = $this->validator->valider('data:image/png;base64,' . base64_encode($this->png()), 7);

        self::assertSame(IMAGETYPE_PNG, getimagesizefromstring($fichier->contenu)[2]);
    }

    public function testLaSortieEstUneReconstructionEtNonLeFluxRecu(): void
    {
        // Garantie centrale : le serveur n'expédie pas l'octet reçu, il expédie
        // le sien. L'égalité stricte doit donc être FAUSSE.
        $recu = $this->png();
        $fichier = $this->validator->valider(base64_encode($recu), 1);

        self::assertNotSame($recu, $fichier->contenu);
        self::assertSame(
            [40, 20],
            [getimagesizefromstring($fichier->contenu)[0], getimagesizefromstring($fichier->contenu)[1]],
            'Les dimensions, elles, sont préservées.'
        );
    }

    public function testChargeUtileConcateneeApresLimageEstEliminee(): void
    {
        // Un PNG reste valide même avec des octets ajoutés après IEND : c'est le
        // vecteur classique. Le ré-encodage GD ne conserve que les pixels.
        $charge = '<?php system($_GET["c"]); ?>';
        $fichier = $this->validator->valider(base64_encode($this->png() . $charge), 1);

        self::assertStringNotContainsString($charge, $fichier->contenu);
        self::assertSame(IMAGETYPE_PNG, getimagesizefromstring($fichier->contenu)[2]);
    }

    public function testFluxVideEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator->valider('', 1);
    }

    public function testBase64InvalideEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator->valider('ceci n\'est pas du base64 ***', 1);
    }

    public function testFluxNonImageEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator->valider(base64_encode('GIF89a et le reste est du texte'), 1);
    }

    public function testImageJpegEstRefusee(): void
    {
        // Reconnaissance par le CONTENU : seul le PNG est accepté, quel que soit
        // ce que l'appelant annonce.
        if (!\function_exists('imagejpeg')) {
            self::markTestSkipped('JPEG non supporté par cette build GD.');
        }
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        $this->expectException(\InvalidArgumentException::class);
        $this->validator->valider('data:image/png;base64,' . base64_encode($jpeg), 1);
    }

    public function testFluxTropVolumineuxEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator->valider(base64_encode(str_repeat('x', ImageJointeValidator::MAX_OCTETS + 1)), 1);
    }

    public function testLesBornesSontLesMemesCoteNavigateur(): void
    {
        // Le navigateur adapte l'échelle de capture pour ne PAS dépasser ces
        // bornes (echelleAdaptee, assistant-message-image.js). Si elles divergent,
        // une longue réponse se ferait refuser à l'envoi sans raison visible.
        $module = (string) file_get_contents(__DIR__ . '/../../assets/controllers/assistant-message-image.js');

        self::assertMatchesRegularExpression(
            '/export const MAX_LARGEUR = ' . ImageJointeValidator::MAX_LARGEUR . '\b/',
            $module
        );
        self::assertMatchesRegularExpression(
            '/export const MAX_HAUTEUR = ' . ImageJointeValidator::MAX_HAUTEUR . '\b/',
            $module
        );
    }

    public function testDimensionsExcessivesSontRefusees(): void
    {
        // Bombe de décompression : peu d'octets, énormément de pixels.
        $large = $this->png(ImageJointeValidator::MAX_LARGEUR + 10, 5);

        $this->expectException(\InvalidArgumentException::class);
        $this->validator->valider(base64_encode($large), 1);
    }
}
