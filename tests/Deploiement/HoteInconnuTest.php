<?php

namespace App\Tests\Deploiement;

use App\EventListener\HoteInconnuListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * « Untrusted Host "mail.joseara.com" » — L'ALERTE QUI REVENAIT TOUTES LES CINQ MINUTES.
 *
 * cPanel sert `mail.joseara.com` depuis le dossier du site ; Symfony (trusted_hosts, parade
 * à l'empoisonnement d'en-tête Host) refusait l'hôte et journalisait un ERROR, que la chaîne
 * d'alerte envoyait par e-mail (dédoublonnage de 5 minutes seulement). Deux étages :
 *  1. `.htaccess` renvoie tout sous-domaine *.joseara.com vers le site, AVANT PHP ;
 *  2. un hôte inconnu qui arrive malgré tout (IP brute, domaine étranger) n'est pas une
 *     panne : 400 sobre, journalisé en INFO — ni e-mail, ni comptage.
 */
final class HoteInconnuTest extends TestCase
{
    private const RACINE = __DIR__ . '/../..';

    public function testLesSousDomainesSontRenvoyesAvantSymfony(): void
    {
        $htaccess = (string) file_get_contents(self::RACINE . '/public/.htaccess');

        $regle = strpos($htaccess, 'RewriteCond %{HTTP_HOST} \.joseara\.com$ [NC]');
        $symfony = strpos($htaccess, 'RÉÉCRITURE SYMFONY');
        self::assertNotFalse($regle, 'La règle des sous-domaines a disparu de public/.htaccess.');
        self::assertNotFalse($symfony);
        self::assertLessThan($symfony, $regle, 'La règle doit précéder la réécriture vers index.php.');

        $bloc = substr($htaccess, $regle, 400);
        self::assertStringContainsString('!^(www\.)?joseara\.com$', $bloc, 'Le site lui-même ne doit pas être renvoyé.');
        self::assertStringContainsString('!^/\.well-known/', $bloc, 'La validation des certificats (AutoSSL) doit passer.');
        self::assertStringContainsString('https://www.joseara.com%{REQUEST_URI} [L,R=301]', $bloc);
    }

    /** Les deux étages parlent des MÊMES hôtes : un écart rouvrirait l'alerte, ou la faille. */
    public function testLesHotesDuSiteSontLesMemesPourApacheEtSymfony(): void
    {
        $config = Yaml::parseFile(self::RACINE . '/config/packages/framework.yaml');

        self::assertSame(['^(www\.)?joseara\.com$'], $config['when@prod']['framework']['trusted_hosts']);
    }

    private function evenement(\Throwable $exception): ExceptionEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new ExceptionEvent($kernel, Request::create('http://mail.joseara.com/'), HttpKernelInterface::MAIN_REQUEST, $exception);
    }

    public function testUnHoteInconnuRepond400SansAlerte(): void
    {
        $journal = [];
        $ecouteur = new HoteInconnuListener(new class($journal) extends AbstractLogger {
            public function __construct(private array &$journal)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->journal[] = (string) $level;
            }
        });
        $evenement = $this->evenement(new BadRequestHttpException(
            'Untrusted Host "mail.joseara.com".',
            new SuspiciousOperationException('Untrusted Host "mail.joseara.com".'),
        ));

        $ecouteur($evenement);

        self::assertSame(400, $evenement->getResponse()?->getStatusCode());
        self::assertTrue($evenement->isPropagationStopped(), 'Le journal d\'erreur de Symfony ne doit pas s\'en saisir.');
        self::assertSame(['info'], $journal, 'Une trace, mais pas une alerte.');
    }

    public function testUnAutre400DeNosRoutesResteUneAlerte(): void
    {
        $ecouteur = new HoteInconnuListener(new class extends AbstractLogger {
            public function log($level, \Stringable|string $message, array $context = []): void
            {
            }
        });
        $evenement = $this->evenement(new BadRequestHttpException('Paramètre manquant.'));

        $ecouteur($evenement);

        self::assertNull($evenement->getResponse());
        self::assertFalse($evenement->isPropagationStopped());
    }
}
