<?php

namespace App\Tests\Security;

use App\Entity\Entreprise;
use App\Form\EntrepriseType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * UN FICHIER DÉPOSÉ NE S'EXÉCUTE JAMAIS SUR JOSEARA.COM (chantier 13).
 *
 * Deux dossiers publics reçoivent des fichiers des utilisateurs, servis par Apache/LiteSpeed
 * — donc hors PHP, hors de la politique de sécurité de `PolitiqueCsp`. Un `.html` ou un `.svg`
 * piégé y serait ouvert DEPUIS NOTRE ORIGINE : ses scripts liraient la session du cabinet.
 *
 * - public/uploads/documents : l'application les sert TOUTES par son contrôleur (droits,
 *   cabinet, pièce jointe — DocumentController::downloadApi). L'accès direct par URL est fermé.
 * - public/uploads/logos (chantier 18 ; avant : public/images/entreprises) : les logos, montrés par <img>. Le formulaire n'accepte que des
 *   images matricielles (le SVG peut porter du script) ; le dossier ne sert que des images, sous
 *   une politique `sandbox` qui interdit tout script même si un fichier passait.
 *
 * Les .htaccess ne s'éprouvent pas ici (pas d'Apache) : leur contenu est tenu par ce test, leur
 * effet réel a été vérifié sous Apache à leur écriture.
 */
class TeleversementsInertesTest extends KernelTestCase
{
    private const RACINE = __DIR__ . '/../..';

    /** @var list<string> */
    private array $temporaires = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaires as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function fichier(string $nom, string $contenu): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'jsb');
        file_put_contents($chemin, $contenu);
        $this->temporaires[] = $chemin;

        return new UploadedFile($chemin, $nom, null, null, true);
    }

    /** @return list<string> erreurs du champ logo après soumission */
    private function erreursDuLogo(UploadedFile $fichier): array
    {
        self::bootKernel();
        $formulaire = static::getContainer()->get(FormFactoryInterface::class)->create(EntrepriseType::class, new Entreprise());
        $formulaire->submit(['thumbnailFile' => $fichier], false);

        return array_map(static fn ($e): string => $e->getMessage(), iterator_to_array($formulaire->get('thumbnailFile')->getErrors()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function fichiersActifs(): iterable
    {
        yield 'SVG avec script' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'];
        yield 'page HTML' => ['logo.html', '<html><body><script>alert(1)</script></body></html>'];
        yield 'HTML déguisé en .png' => ['logo.png', '<html><script>alert(1)</script></html>'];
    }

    /** @dataProvider fichiersActifs */
    public function testLeLogoRefuseUnFichierActif(string $nom, string $contenu): void
    {
        self::assertNotSame([], $this->erreursDuLogo($this->fichier($nom, $contenu)), "« $nom » ne doit pas être accepté comme logo.");
    }

    /** @return iterable<string, array{string, string}> de vraies images, 1 × 1 pixel */
    public static function images(): iterable
    {
        yield 'PNG' => ['logo.png', 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='];
        yield 'GIF' => ['logo.gif', 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw=='];
        yield 'JPEG' => ['logo.jpg', '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='];
    }

    /** @dataProvider images */
    public function testLeLogoAccepteUneImage(string $nom, string $base64): void
    {
        self::assertSame([], $this->erreursDuLogo($this->fichier($nom, (string) base64_decode($base64))));
    }

    public function testLesDocumentsDeposesNeSontJamaisServisDirectement(): void
    {
        $htaccess = (string) @file_get_contents(self::RACINE . '/public/uploads/documents/.htaccess');

        self::assertMatchesRegularExpression('/^\s*Require all denied\s*$/m', $htaccess, 'public/uploads/documents : accès direct fermé (le contrôleur sert les fichiers).');
    }

    /** Le verrou doit PARTIR en production : le dossier est ignoré par git, le .htaccess non. */
    public function testLeVerrouDesDocumentsEstVersionne(): void
    {
        exec('git -C ' . escapeshellarg(self::RACINE) . ' check-ignore -q public/uploads/documents/.htaccess', $sortie, $code);

        self::assertSame(1, $code, 'public/uploads/documents/.htaccess est ignoré par git : il ne partirait pas en production (exception à garder dans .gitignore).');
    }

    /** @return iterable<string, array{string}> */
    public static function dossiersDImages(): iterable
    {
        yield 'logos déposés (chantier 18)' => ['public/uploads/logos'];
        yield 'images de marque' => ['public/images/entreprises'];
    }

    /** @dataProvider dossiersDImages */
    public function testLeDossierDesLogosRefuseLesTypesActifsSousSandbox(string $dossier): void
    {
        $htaccess = (string) @file_get_contents(self::RACINE . '/' . $dossier . '/.htaccess');

        // Les types ACTIFS refusés — et pas « tout refuser puis accorder les images », dont
        // la levée n'est pas éprouvée sous LiteSpeed (un échec couperait tous les logos).
        preg_match_all('/<FilesMatch "\\\\\.\(([^)]+)\)\$">\s*Require all denied\s*<\/FilesMatch>/', $htaccess, $m);
        $refuses = implode('|', $m[1]); // tous les blocs : PHP (déjà là) et types actifs
        foreach (['svgz?', 'x?html?', 'xml', 'js', 'php'] as $actif) {
            self::assertStringContainsString($actif, $refuses, "Type actif non refusé : $actif");
        }
        self::assertDoesNotMatchRegularExpression('/Require all granted/', $htaccess);
        self::assertMatchesRegularExpression('/Header always set Content-Security-Policy "[^"]*default-src \'none\'[^"]*sandbox/', $htaccess, 'Politique sandbox sur chaque réponse.');
        self::assertStringContainsString('X-Content-Type-Options "nosniff"', $htaccess);
    }

    /**
     * L'EMPLACEMENT D'UNE PIÈCE A UNE SOURCE (chantier 18) : le paramètre
     * `app.dossier_des_pieces`, lu par Vich et la purge d'un cabinet ; le code passe par
     * DocumentFichier::chemin(). Un chemin écrit en dur casserait au déplacement du dossier.
     */
    public function testAucunCheminDePieceEcritEnDurDansLeCode(): void
    {
        $fautifs = [];
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RACINE . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($fichiers as $fichier) {
            if ($fichier->getExtension() === 'php' && preg_match('#uploads/documents#', (string) file_get_contents($fichier->getPathname()))) {
                $fautifs[] = substr($fichier->getPathname(), \strlen(self::RACINE) + 1);
            }
        }

        self::assertSame([], $fautifs, 'Passer par DocumentFichier::chemin() (ou le paramètre app.dossier_des_pieces).');
    }
}
