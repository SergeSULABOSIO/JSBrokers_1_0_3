<?php

namespace App\Tests\Ai;

use App\Ai\Fichier\FichierTexteExtracteur;
use App\Ai\Mutation\ConversationFichierRef;
use App\Ai\Mutation\MutationReferences;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Unitaire : le marqueur de pièce jointe « @fichier:<id> » (reconnaissance,
 * extraction d'id) et sa NON-collision avec les renvois de plan « @étiquette »
 * (MutationReferences l'exclut). Extraction de texte best-effort (.txt).
 */
class ConversationFichierRefTest extends TestCase
{
    public function testReconnaissanceDuMarqueur(): void
    {
        $this->assertTrue(ConversationFichierRef::estMarqueur('@fichier:7'));
        $this->assertSame(7, ConversationFichierRef::id('@fichier:7'));
        $this->assertSame('@fichier:42', ConversationFichierRef::marqueur(42));

        // Formes invalides.
        $this->assertFalse(ConversationFichierRef::estMarqueur('@fichier:'));
        $this->assertFalse(ConversationFichierRef::estMarqueur('@fichier:abc'));
        $this->assertFalse(ConversationFichierRef::estMarqueur('@client'));
        $this->assertNull(ConversationFichierRef::id('@client'));
    }

    public function testExclusionDesRenvoisDePlan(): void
    {
        // Un renvoi de plan reste un renvoi.
        $this->assertTrue(MutationReferences::estReference('@client'));
        // Le marqueur fichier N'EST PAS un renvoi (sinon faux « manquant »).
        $this->assertFalse(MutationReferences::estReference('@fichier:7'));
    }

    public function testExtractionTexteSimple(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpunit_ex_');
        file_put_contents($path, "Bonjour Ket, ceci est un extrait.");
        $upload = new UploadedFile($path, 'note.txt', 'text/plain', null, true);

        $texte = (new FichierTexteExtracteur())->extraire($upload);
        $this->assertNotNull($texte);
        $this->assertStringContainsString('extrait', $texte);

        @unlink($path);
    }

    public function testExtractionFormatNonLisibleRenvoieNull(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpunit_ex_');
        file_put_contents($path, 'binaire');
        $upload = new UploadedFile($path, 'image.png', 'image/png', null, true);

        $this->assertNull((new FichierTexteExtracteur())->extraire($upload));

        @unlink($path);
    }

    public function testExtractionDocx(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpunit_docx_');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0"?><w:document><w:body><w:p><w:r><w:t>Rapport annuel du client Dupont</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Prime totale 12000 EUR</w:t></w:r></w:p></w:body></w:document>',
        );
        $zip->close();

        $upload = new UploadedFile($path, 'rapport.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        $texte = (new FichierTexteExtracteur())->extraire($upload);
        $this->assertNotNull($texte);
        $this->assertStringContainsString('Dupont', $texte);
        $this->assertStringContainsString('12000', $texte);

        @unlink($path);
    }

    public function testExtractionXlsx(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpunit_xlsx_');
        $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $feuille = $classeur->getActiveSheet();
        $feuille->setCellValue('A1', 'Client')->setCellValue('B1', 'Prime');
        $feuille->setCellValue('A2', 'Dupont')->setCellValue('B2', 12000);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur))->save($path);

        $upload = new UploadedFile($path, 'donnees.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $texte = (new FichierTexteExtracteur())->extraire($upload);
        $this->assertNotNull($texte);
        $this->assertStringContainsString('Dupont', $texte);
        $this->assertStringContainsString('12000', $texte);

        @unlink($path);
    }
}
