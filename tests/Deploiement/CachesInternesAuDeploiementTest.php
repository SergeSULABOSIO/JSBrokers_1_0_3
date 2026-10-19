<?php

namespace App\Tests\Deploiement;

use PHPUnit\Framework\TestCase;

/**
 * LES CACHES INTERNES DE SYMFONY SONT VIDÉS À CHAQUE DÉPLOIEMENT.
 *
 * L'INCIDENT (montée Symfony 7.1 → 7.4, 2026-10-19). Le format des fichiers du pool
 * `cache.system` a changé (PhpFilesAdapter : `static fn () => …` en 7.1, objet
 * `CachedValueInterface` en 7.4). Des fichiers écrits par 7.1 ont survécu à un
 * `cache:clear` : 7.4 les relisait comme une valeur ordinaire et rendait une Closure là où
 * ux-icons attendait une icône — 500 sur toute page portant une icône. Le pool système
 * (métadonnées, icônes, sérialiseur) se reconstruit seul : on le vide explicitement après
 * `cache:clear`, AVANT le préchauffage, pour ne jamais dépendre de ce que l'ancien code a
 * laissé.
 */
final class CachesInternesAuDeploiementTest extends TestCase
{
    public function testLePoolSystemeEstVideEntreLeNettoyageEtLePrechauffage(): void
    {
        $script = (string) file_get_contents(\dirname(__DIR__, 2) . '/bin/deploy.sh');

        $nettoyage = strpos($script, 'bin/console cache:clear --no-warmup');
        $pool = strpos($script, 'bin/console cache:pool:clear cache.system_clearer');
        $prechauffage = strpos($script, 'bin/console cache:warmup');

        self::assertNotFalse($nettoyage, 'cache:clear introuvable dans deploy.sh.');
        self::assertNotFalse($pool, 'deploy.sh ne vide pas le pool système : un format de cache périmé survivrait à la montée de version.');
        self::assertNotFalse($prechauffage, 'cache:warmup introuvable dans deploy.sh.');
        self::assertTrue($nettoyage < $pool && $pool < $prechauffage, 'Ordre attendu : cache:clear, puis le pool système, puis cache:warmup.');
    }
}
