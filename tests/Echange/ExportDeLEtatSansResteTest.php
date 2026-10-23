<?php

namespace App\Tests\Echange;

use App\Echange\Etat\ProducteurDeLEtat;
use App\Service\Document\EnvoiPuisEffacement;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'ÉTAT EXPORTÉ NE RESTE PAS SUR LE SERVEUR, MÊME TÉLÉCHARGEMENT INTERROMPU (Serge, 2026-10-23).
 *
 * La réponse lisait le fichier temporaire puis l'effaçait : un téléchargement coupé en route
 * arrêtait PHP avant l'effacement, et l'état du portefeuille du cabinet restait dans le dossier
 * temporaire du système. `EnvoiPuisEffacement` efface dans un `finally`, coupure ignorée — et
 * envoie d'UN SEUL TENANT : la réponse de fichier de Symfony honorait `Range` (206), et un
 * gestionnaire de téléchargement aurait recollé des morceaux de fichiers différents.
 */
class ExportDeLEtatSansResteTest extends KernelTestCase
{
    public function testLEtatEstEnvoyeDUnSeulTenantPuisEfface(): void
    {
        self::bootKernel();
        $producteur = static::getContainer()->get(ProducteurDeLEtat::class);
        $classeur = new Spreadsheet();
        $classeur->getActiveSheet()->setCellValue('A1', 'Client « Ma » <Société> & fils');

        $reponse = (new \ReflectionMethod(ProducteurDeLEtat::class, 'reponse'))->invoke($producteur, $classeur, 'etat.xlsx');
        $fichier = $this->fichierDe($reponse);
        self::assertFileExists($fichier);

        $this->verifierEnvoiPuisEffacement($reponse, $fichier, 'etat.xlsx');
    }

    public function testUnFichierPrepareEstEnvoyeDUnSeulTenantPuisEfface(): void
    {
        $fichier = (string) tempnam(sys_get_temp_dir(), 'phpunit_env_');
        file_put_contents($fichier, 'PK' . str_repeat('x', 4000));

        $this->verifierEnvoiPuisEffacement(EnvoiPuisEffacement::reponse($fichier, 'export.xlsx'), $fichier, 'export.xlsx');
    }

    private function verifierEnvoiPuisEffacement(Response $reponse, string $fichier, string $nom): void
    {
        $taille = filesize($fichier);

        // Un gestionnaire de téléchargement qui découpe : la réponse reste ENTIÈRE.
        $requete = Request::create('/');
        $requete->headers->set('Range', 'bytes=0-99');
        $reponse->prepare($requete);
        self::assertSame(200, $reponse->getStatusCode(), 'Jamais de morceau (206).');
        self::assertSame('none', $reponse->headers->get('Accept-Ranges'));
        self::assertSame((string) $taille, $reponse->headers->get('Content-Length'));
        self::assertStringContainsString('attachment; filename=' . $nom, (string) $reponse->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $reponse->headers->get('Cache-Control'));

        ob_start();
        $reponse->sendContent();
        $contenu = (string) ob_get_clean();

        self::assertSame($taille, \strlen($contenu), 'Le fichier part entier.');
        self::assertStringStartsWith('PK', $contenu);
        self::assertFileDoesNotExist($fichier, 'Envoyé : le fichier est effacé.');
        self::assertSame(0, ignore_user_abort(), 'Le réglage de coupure est rendu tel qu\'il était.');
    }

    /** Le chemin capturé par la fonction de flux. */
    private function fichierDe(Response $reponse): string
    {
        $rappel = (new \ReflectionProperty($reponse, 'callback'))->getValue($reponse);

        return (string) (new \ReflectionFunction($rappel))->getStaticVariables()['chemin'];
    }
}
