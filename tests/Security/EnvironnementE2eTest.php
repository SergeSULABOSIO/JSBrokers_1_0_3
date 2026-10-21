<?php

namespace App\Tests\Security;

use App\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * L'ENVIRONNEMENT e2e EST IMPOSSIBLE À ACTIVER EN PRODUCTION (chantier 15, décision de Serge).
 *
 * L'environnement e2e (navigateur piloté, sessions natives dans var/sessions/e2e, base de
 * test) ne démarre que sur un poste qui porte `.env.e2e.local` — fichier exclu de git par
 * `/.env.*.local`, donc jamais déployé —, et ne répond qu'aux requêtes LOCALES. Ni `.env`,
 * ni la configuration Apache de production ne peuvent l'activer.
 */
class EnvironnementE2eTest extends TestCase
{
    private const RACINE = __DIR__ . '/../..';

    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir() . '/jsb-e2e-' . bin2hex(random_bytes(4));
        mkdir($this->dossier);
    }

    protected function tearDown(): void
    {
        @unlink($this->dossier . '/.env.e2e.local');
        @rmdir($this->dossier);
    }

    public function testSansLeFichierDuPosteDeDeveloppementE2eNeDemarrePas(): void
    {
        $this->expectException(\LogicException::class);
        Kernel::exigerUnPosteDeDeveloppement('e2e', $this->dossier, 'cli', null);
    }

    public function testE2eNeRepondQuAuxRequetesLocales(): void
    {
        touch($this->dossier . '/.env.e2e.local');

        Kernel::exigerUnPosteDeDeveloppement('e2e', $this->dossier, 'cli', null);
        Kernel::exigerUnPosteDeDeveloppement('e2e', $this->dossier, 'apache2handler', '127.0.0.1');
        Kernel::exigerUnPosteDeDeveloppement('e2e', $this->dossier, 'apache2handler', '::1');

        $this->expectException(\LogicException::class);
        Kernel::exigerUnPosteDeDeveloppement('e2e', $this->dossier, 'apache2handler', '41.243.10.7');
    }

    /** Un tunnel local (ngrok, cloudflared…) fait passer Internet pour 127.0.0.1 : il le trahit par X-Forwarded-For. */
    public function testE2eRefuseUneRequeteRelayeeParUnTunnel(): void
    {
        touch($this->dossier . '/.env.e2e.local');

        $this->expectException(\LogicException::class);
        Kernel::exigerUnPosteDeDeveloppement('e2e', $this->dossier, 'apache2handler', '127.0.0.1', true);
    }

    public function testLesAutresEnvironnementsNeSontPasConcernes(): void
    {
        foreach (['prod', 'dev', 'test'] as $env) {
            Kernel::exigerUnPosteDeDeveloppement($env, $this->dossier, 'apache2handler', '41.243.10.7');
        }
        $this->addToAssertionCount(3);
    }

    public function testLeFichierDuPosteNEstJamaisVersionne(): void
    {
        self::assertMatchesRegularExpression('#^/\.env\.\*\.local$#m', (string) file_get_contents(self::RACINE . '/.gitignore'));
    }

    /** Aucun fichier versionné ni déployé ne choisit l'environnement e2e. */
    public function testRienDeVersionneNeLActive(): void
    {
        $fichiers = ['.env', '.env.prod', 'public/.htaccess', '.cpanel.yml', 'bin/deploy.sh', 'bin/publier.ps1'];
        foreach ($fichiers as $relatif) {
            $chemin = self::RACINE . '/' . $relatif;
            if (!is_file($chemin)) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/APP_ENV\s*[=:]?\s*["\']?e2e\b|--env[= ]e2e\b/i', (string) file_get_contents($chemin), $relatif);
        }
    }
}
