<?php

namespace App\Tests\Deploiement;

use PHPUnit\Framework\TestCase;

/**
 * LES BLOCS DE L'HÉBERGEUR DANS public/.htaccess NE BLOQUENT PLUS LE DÉPLOIEMENT.
 *
 * Le 2026-10-08, cPanel a écrit son gestionnaire PHP dans public/.htaccess, fichier
 * versionné ; « publier » s'est arrêté sur « fichier suivi modifié sur le serveur ». Et
 * le bloc imposait PHP 8.1 au SITE quand l'application exige 8.2 — ce que le contrôle du
 * PHP de la ligne de commande ne pouvait pas voir.
 *
 * Ce test exécute le VRAI outil (bin/htaccess-hebergeur.sh) sur le VRAI fichier du dépôt
 * augmenté du bloc constaté ce jour-là, et vérifie que bin/deploy.sh s'en sert.
 */
class HtaccessHebergeurTest extends TestCase
{
    /** Le bloc écrit par cPanel le 2026-10-08, tel que `git diff` l'a montré sur le serveur. */
    private const BLOC_CPANEL = "# php -- BEGIN cPanel-generated handler, do not edit\n"
        . "# Set the “ea-php81” package as the default “PHP” programming language.\n"
        . "<IfModule mime_module>\n"
        . "  AddHandler application/x-httpd-ea-php81 .php .php8 .phtml\n"
        . "</IfModule>\n"
        . "# php -- END cPanel-generated handler, do not edit\n";

    private string $dossier;

    protected function setUp(): void
    {
        if (trim((string) @shell_exec('bash -c "echo ok" 2>&1')) !== 'ok') {
            self::markTestSkipped('bash est introuvable : l\'outil de déploiement ne peut pas être exécuté ici.');
        }
        $this->dossier = sys_get_temp_dir() . '/htaccess-hebergeur-' . bin2hex(random_bytes(4));
        mkdir($this->dossier);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dossier . '/*') ?: []);
        @rmdir($this->dossier);
    }

    private static function racine(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Le fichier du dépôt, en fins de ligne Unix — comme sur le serveur. */
    private function depot(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(self::racine() . '/public/.htaccess'));
    }

    /** Le fichier tel que le serveur le portait : le dépôt, une ligne vide, le bloc cPanel. */
    private function serveur(): string
    {
        return $this->depot() . "\n" . self::BLOC_CPANEL;
    }

    private function outil(string $commande, string $contenu): string
    {
        $fichier = $this->dossier . '/htaccess';
        file_put_contents($fichier, $contenu);
        $script = str_replace('\\', '/', self::racine() . '/bin/htaccess-hebergeur.sh');

        return (string) shell_exec(sprintf('bash %s %s %s 2>&1', escapeshellarg($script), $commande, escapeshellarg(str_replace('\\', '/', $fichier))));
    }

    public function testLeDepotNePorteAucunBloc(): void
    {
        self::assertSame('', $this->outil('blocs', $this->depot()), 'Le critère « tout bloc marqué vient de l\'hébergeur » exige un dépôt sans marqueur.');
    }

    public function testLeBlocDeLHebergeurEstReconnuTelQuel(): void
    {
        self::assertSame(self::BLOC_CPANEL, $this->outil('blocs', $this->serveur()));
    }

    /** Sans ses blocs, le fichier du serveur EST celui du dépôt : aucune fausse alerte. */
    public function testHorsDesBlocsLeFichierEstCeluiDuDepot(): void
    {
        self::assertSame($this->depot(), $this->outil('sans-blocs', $this->serveur()));
    }

    /** Remettre les blocs après le reset redonne, à l'octet près, le fichier du serveur. */
    public function testRemettreLesBlocsRedonneLeFichierDuServeur(): void
    {
        self::assertSame($this->serveur(), $this->depot() . "\n" . $this->outil('blocs', $this->serveur()));
    }

    /** Une retouche HORS des blocs se voit : le déploiement continue de la refuser. */
    public function testUneRetoucheHorsDesBlocsSeVoit(): void
    {
        $retouche = str_replace('RewriteEngine On', 'RewriteEngine Off', $this->serveur());

        self::assertNotSame($this->depot(), $this->outil('sans-blocs', $retouche));
    }

    /** Le PHP imposé au SITE se lit — c'est lui que le déploiement compare au minimum. */
    public function testLePhpImposeAuSiteEstLu(): void
    {
        self::assertSame("8.1\n", $this->outil('php-imposes', $this->serveur()));
        self::assertSame('', $this->outil('php-imposes', $this->depot()));
    }

    /**
     * Le déploiement s'en sert : il compare le fichier SANS blocs au dépôt, refuse un PHP
     * imposé sous 8.2, sauvegarde les blocs AVANT le reset et les remet APRÈS.
     */
    public function testLeDeploiementConserveLesBlocsEtRefuseUnPhpTropAncien(): void
    {
        $script = (string) file_get_contents(self::racine() . '/bin/deploy.sh');

        self::assertStringContainsString('htaccess-hebergeur.sh sans-blocs', $script);
        self::assertStringContainsString('htaccess-hebergeur.sh php-imposes', $script);
        self::assertMatchesRegularExpression('/\[ "\$MAJ" -eq 8 \] && \[ "\$MIN" -lt 2 \]/', $script, 'Le minimum comparé est PHP 8.2.');

        $sauvegarde = strpos($script, 'htaccess-hebergeur.sh blocs');
        $reset = strpos($script, 'executer "git reset --hard');
        $remise = strpos($script, 'cat \'$BLOCS_HEBERGEUR\'');
        self::assertNotFalse($sauvegarde);
        self::assertNotFalse($remise);
        self::assertLessThan($reset, $sauvegarde, 'Les blocs se sauvegardent AVANT le reset.');
        self::assertGreaterThan($reset, $remise, 'Ils sont remis APRÈS le reset.');
    }
}
