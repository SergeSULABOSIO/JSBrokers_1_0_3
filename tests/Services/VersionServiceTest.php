<?php

namespace App\Tests\Services;

use App\Services\VersionService;
use PHPUnit\Framework\TestCase;

/**
 * Règle de numérotation de la version applicative : la base (3881 commits) vaut
 * 1.0, chaque commit ajoute +1, et 1000 commits font passer de 1.x à 2.x.
 * Vérifie aussi la lecture du fichier VERSION et les replis.
 */
class VersionServiceTest extends TestCase
{
    public function testFormatBornesNumerotation(): void
    {
        $base = 3881;

        $this->assertSame('1.0', VersionService::format($base), 'La base = 1.0');
        $this->assertSame('1.1', VersionService::format($base + 1), 'Premier commit = 1.1');
        $this->assertSame('1.42', VersionService::format($base + 42));
        $this->assertSame('1.999', VersionService::format($base + 999), 'Dernier mineur avant bascule');
        $this->assertSame('2.0', VersionService::format($base + 1000), '1000 commits → 2.0');
        $this->assertSame('2.1', VersionService::format($base + 1001));
        $this->assertSame('3.0', VersionService::format($base + 2000));
    }

    public function testFormatClampeSousLaBase(): void
    {
        // Un décompte inférieur à la base (dépôt tronqué) ne descend jamais sous 1.0.
        $this->assertSame('1.0', VersionService::format(0));
        $this->assertSame('1.0', VersionService::format(100));
    }

    public function testLitLeFichierVersion(): void
    {
        $dir = $this->projetTemporaire("3882\n2026-07-25\n");

        $service = new VersionService($dir);

        $this->assertSame('1.1', $service->getVersion());
        $this->assertSame('2026-07-25', $service->getDate()->format('Y-m-d'));
    }

    public function testFichierAbsentRetombeSurLaBase(): void
    {
        // Dossier sans fichier VERSION ni dépôt git : repli déterministe sur 1.0.
        $dir = sys_get_temp_dir() . '/jsb_version_' . uniqid('', true);
        mkdir($dir);

        $service = new VersionService($dir);

        $this->assertSame('1.0', $service->getVersion());

        rmdir($dir);
    }

    private function projetTemporaire(string $contenuVersion): string
    {
        $dir = sys_get_temp_dir() . '/jsb_version_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/VERSION', $contenuVersion);

        // Nettoyage en fin de test.
        register_shutdown_function(static function () use ($dir): void {
            @unlink($dir . '/VERSION');
            @rmdir($dir);
        });

        return $dir;
    }
}
