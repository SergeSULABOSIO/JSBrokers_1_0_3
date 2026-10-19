<?php

namespace App\Tests\Deploiement;

use PHPUnit\Framework\TestCase;

/**
 * « publier » NE FAIT PLUS DE `git pull` — deploy.sh se met à jour lui-même.
 *
 * Le 2026-10-19, `publier` s'est arrêté net : « Your local changes to public/.htaccess would
 * be overwritten by merge ». Le .htaccess du serveur porte les blocs de l'hébergeur (remis
 * par deploy.sh à chaque publication) ; le premier commit touchant ce fichier a bloqué le
 * `git pull` de l'alias. deploy.sh récupère lui-même la version visée et la protège ; il se
 * relance désormais dans sa propre nouvelle version, ce qui rend le `git pull` inutile.
 */
final class PublierSansGitPullTest extends TestCase
{
    private const RACINE = __DIR__ . '/../..';

    public function testLeRaccourciDocumenteNeFaitPasDeGitPull(): void
    {
        $readme = (string) file_get_contents(self::RACINE . '/deploiement/README.md');
        preg_match_all("/alias publier='([^']*)'/", $readme, $alias);

        self::assertNotEmpty($alias[1], 'Le raccourci « publier » n\'est plus documenté.');
        foreach ($alias[1] as $commande) {
            self::assertStringNotContainsString('git pull', $commande);
            self::assertStringContainsString('bash bin/deploy.sh', $commande);
        }
    }

    public function testDeploySeRelanceDansSaVersionViseeAvantToutGeste(): void
    {
        $script = (string) file_get_contents(self::RACINE . '/bin/deploy.sh');

        $relance = strpos($script, 'exec bash "$SCRIPT_VISE"');
        $etape1 = strpos($script, 'titre "1/11');
        $maintenance = strpos($script, "maintenance.flag'\"");

        self::assertNotFalse($relance, 'deploy.sh ne se relance plus dans sa version visée.');
        self::assertLessThan($etape1, $relance, 'La relance doit précéder toute vérification.');
        self::assertLessThan($maintenance, $relance, 'La relance doit précéder la mise en maintenance.');
        self::assertStringContainsString('JOSEARA_DEPLOY_RELANCE', substr($script, $relance - 600, 700), 'Une seule relance.');
    }
}
