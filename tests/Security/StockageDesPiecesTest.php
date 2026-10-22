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
