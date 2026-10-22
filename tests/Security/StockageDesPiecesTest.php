<?php

namespace App\Tests\Security;

use App\Command\DeplacerLesPiecesCommand;
use App\Service\Document\NomAleatoire;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * LES FICHIERS DÉPOSÉS VIVENT HORS DE LA RACINE WEB (chantier 18, décisions de Serge).
 * Seuls les logos restent publics — dans leur propre dossier, sous noms aléatoires.
 */
final class StockageDesPiecesTest extends KernelTestCase
{
    private const RACINE = __DIR__ . '/../..';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    private static function sousPublic(string $chemin): bool
    {
        return str_contains(str_replace('\\', '/', $chemin), '/public/');
    }

    public function testLesPiecesSontHorsDeLaRacineWeb(): void
    {
        $dossier = (string) static::getContainer()->getParameter('app.dossier_des_pieces');

        self::assertFalse(self::sousPublic($dossier), "app.dossier_des_pieces est sous public/ : $dossier");
        self::assertStringEndsWith('/var/uploads/documents', str_replace('\\', '/', $dossier));
    }

    /** Aucun mapping Vich de données d'utilisateur sous public/ — sauf les logos, motivé. */
    public function testAucunMappingDeDonneesSousPublicSaufLesLogos(): void
    {
        $permis = ['entreprises' => 'logos des cabinets, montrés par <img> (noms aléatoires, .htaccess images seulement)'];
        $config = Yaml::parseFile(self::RACINE . '/config/packages/vich_uploader.yaml');
        $sousPublic = [];
        foreach ($config['vich_uploader']['mappings'] as $nom => $mapping) {
            $destination = (string) static::getContainer()->getParameterBag()->resolveValue($mapping['upload_destination']);
            if (self::sousPublic($destination) && !isset($permis[$nom])) {
                $sousPublic[] = "$nom → $destination";
            }
        }

        self::assertSame([], $sousPublic, 'Un mapping de données d’utilisateur sous public/ serait servi par URL directe.');
    }

    public function testLesPiecesEtLesLogosOntDesNomsAleatoires(): void
    {
        $config = Yaml::parseFile(self::RACINE . '/config/packages/vich_uploader.yaml')['vich_uploader']['mappings'];
        foreach (['piece_sinistre_documents', 'entreprises'] as $mapping) {
            self::assertSame(NomAleatoire::class, $config[$mapping]['namer'] ?? null, $mapping);
        }

        $a = NomAleatoire::nouveau('PDF');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $a, '128 bits aléatoires + extension en minuscules');
        self::assertNotSame($a, NomAleatoire::nouveau('pdf'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.phpexe$/', NomAleatoire::nouveau('p.h/p\\exe'), 'extension réduite à [a-z0-9]');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', NomAleatoire::nouveau(''));
    }

    /**
     * Un LOGO (public) prend l'extension de son CONTENU, dans une liste fermée : une image
     * valide annoncée « x.php » (ou .pht, .xht) n'est jamais stockée sous cette extension.
     */
    public function testUnLogoPrendLExtensionDeSonContenu(): void
    {
        $png = sys_get_temp_dir() . '/logo-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($png, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        try {
            $fichier = new \Symfony\Component\HttpFoundation\File\UploadedFile($png, 'logo.php', null, null, true);

            self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', NomAleatoire::pourUnLogo($fichier));
            // Un contenu qui n'est pas une image de la liste : aucune extension.
            file_put_contents($png, '<?php echo 1;');
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', NomAleatoire::pourUnLogo(new \Symfony\Component\HttpFoundation\File\UploadedFile($png, 'logo.png', null, null, true)));
        } finally {
            @unlink($png);
        }
    }

    /**
     * LE DOSSIER DES LOGOS EST PUBLIC, ET SON VERROU EST UNE LISTE DE REFUS (écart accepté par
     * Serge, LiteSpeed). La condition : le TÉLÉVERSEMENT DE LOGO est le seul code qui y ÉCRIT
     * (mapping Vich `entreprises`, nommeur `NomAleatoire::pourUnLogo` — liste fermée), le reste
     * du code n'y touche que pour des raisons nommées ci-dessous.
     */
    public function testSeulLeTeleversementDeLogoEcritDansLeDossierDesLogos(): void
    {
        $motifs = [
            'NotePdf.php' => 'LIT le logo pour le PDF de la note',
            'ServiceSuppressionEntreprise.php' => 'SUPPRIME le logo d\'un cabinet supprimé',
            'DeplacerLesPiecesCommand.php' => 'COPIE les anciens logos, extensions de la liste fermée seulement (testé)',
        ];
        $touchent = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RACINE . '/src', \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php' && preg_match('#dossier_des_logos|uploads/logos#', (string) file_get_contents($f->getPathname()))) {
                $touchent[] = $f->getFilename();
            }
        }
        sort($touchent);
        $permis = array_keys($motifs);
        sort($permis);
        self::assertSame($permis, $touchent, 'Un nouveau code touche au dossier public des logos : le motiver ici, ou passer par le téléversement.');

        // Et le téléversement lui-même : seul mapping vers ce dossier, nommeur à liste fermée.
        $mappings = Yaml::parseFile(self::RACINE . '/config/packages/vich_uploader.yaml')['vich_uploader']['mappings'];
        $versLogos = array_keys(array_filter($mappings, static fn (array $m): bool => str_contains((string) $m['upload_destination'], 'dossier_des_logos')));
        self::assertSame(['entreprises'], $versLogos);
        self::assertSame(['png', 'jpg', 'gif', 'webp'], NomAleatoire::EXTENSIONS_DES_LOGOS);
        foreach (DeplacerLesPiecesCommand::EXTENSIONS_DE_LOGO as $extension) {
            self::assertContains($extension === 'jpeg' ? 'jpg' : $extension, NomAleatoire::EXTENSIONS_DES_LOGOS);
        }
    }

    /** Aucun gabarit n'écrit l'adresse d'un logo : Vich la donne (vich_uploader_asset). */
    public function testAucuneAdresseDeLogoEcriteEnDurDansLesGabarits(): void
    {
        $fautifs = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RACINE . '/templates', \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (preg_match('#images/entreprises/[\'"]\s*~#', (string) file_get_contents($f->getPathname()))) {
                $fautifs[] = $f->getFilename();
            }
        }

        self::assertSame([], $fautifs, "Utiliser vich_uploader_asset(entreprise, 'thumbnailFile').");
    }

    /** La commande ne supprime jamais un fichier du dépôt : la liste suit git. */
    public function testLesFichiersDuDepotSontCeuxQueGitSuit(): void
    {
        exec('git -C ' . escapeshellarg(self::RACINE) . ' ls-files public/images/entreprises', $sortie, $code);
        self::assertSame(0, $code);
        $suivis = array_values(array_filter(array_map('basename', $sortie), static fn (string $f): bool => $f !== '.htaccess'));
        sort($suivis);
        $declares = DeplacerLesPiecesCommand::FICHIERS_DU_DEPOT;
        sort($declares);

        self::assertSame($suivis, $declares);
    }
}
