<?php

namespace App\Tests\Workspace;

use App\Entity\Document;
use App\Form\DocumentType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LA CASE « PARTAGÉE AVEC LE CLIENT » (chantier 18, option (c) de Serge) par le formulaire —
 * celui de l'écran ET de Ket (WorkspaceMutationService soumet DocumentType). Le moteur de Ket
 * normalise les booléens en '1'/'0' : '0' ne doit JAMAIS partager.
 */
final class DocumentPartageeAvecLeClientTest extends KernelTestCase
{
    private function soumettre(array $donnees): Document
    {
        self::bootKernel();
        $document = (new Document())->setNom('Pièce');
        $document->setNomFichierStocke('existante.pdf'); // le fichier n'est alors pas exigé
        (new \ReflectionProperty(Document::class, 'id'))->setValue($document, 1); // lien de téléchargement
        $formulaire = static::getContainer()->get('form.factory')->create(DocumentType::class, $document, ['csrf_protection' => false]);
        $formulaire->submit($donnees + ['nom' => 'Pièce'], false);

        return $document;
    }

    public function testDecocheeParDefaut(): void
    {
        self::assertFalse($this->soumettre([])->isPartageeAvecLeClient());
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function valeurs(): iterable
    {
        yield "'1' (Ket : oui)" => ['1', true];
        yield "'0' (Ket : non)" => ['0', false];
        yield 'chaîne vide' => ['', false];
        yield "'false'" => ['false', false];
    }

    /** @dataProvider valeurs */
    public function testLaCaseSuitLaValeurSoumise(mixed $valeur, bool $attendu): void
    {
        self::assertSame($attendu, $this->soumettre(['partageeAvecLeClient' => $valeur])->isPartageeAvecLeClient());
    }
}
