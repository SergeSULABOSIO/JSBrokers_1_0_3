<?php

namespace App\Tests\Security;

use App\Entity\ErreurApplicative;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * LA COLLECTE DES VIOLATIONS DE LA CSP (`POST /api/journal/csp`).
 *
 * Les navigateurs envoient deux formats : l'ancien `application/csp-report` (report-uri) et
 * le Reporting API `application/reports+json` (report-to, HTTPS seulement, par lots —
 * vérifiable ici, pas par l'E2E du poste). Chaque violation entre dans la supervision,
 * regroupée par (directive, ressource bloquée, fichier, ligne) avec un compteur.
 *
 * Route publique : n'importe qui peut y écrire. Corps plafonné, débit limité par IP et compté
 * par violation, rapports d'un autre site ignorés, extensions écartées (adresse complète OU
 * schéma nu, forme réelle des rapports CSP3), adresses anonymisées (requête, fragment, jeton
 * du relevé public), jamais d'e-mail d'alerte, réponse 204 toujours.
 */
class JournalCspTest extends WebTestCase
{
    /** L'hôte du client de test est « localhost » : un rapport d'une autre page n'est pas le nôtre. */
    private const PAGE = 'http://localhost/espacedetravail/12/34?jeton=SECRET#onglet';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient(server: ['REMOTE_ADDR' => '203.0.113.' . random_int(1, 254)]);
        $this->purger();
        static::getContainer()->get('limiter.csp_par_ip')->create($this->client->getServerParameter('REMOTE_ADDR'))->reset();
    }

    protected function tearDown(): void
    {
        $this->purger();
        parent::tearDown();
    }

    private function purger(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement("DELETE FROM erreur_applicative WHERE type LIKE 'CSP %' AND (type LIKE '%phpunit-csp%' OR message LIKE '%phpunit-csp%')");
    }

    /** @return list<ErreurApplicative> */
    private function enregistrees(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(ErreurApplicative::class)->createQueryBuilder('e')
            ->where('e.type LIKE :csp')->andWhere('e.type LIKE :t OR e.message LIKE :t')
            ->setParameter('csp', 'CSP %')->setParameter('t', '%phpunit-csp%')
            ->orderBy('e.id')->getQuery()->getResult();
    }

    /** @param array<string, mixed> $surcharges */
    private function ancienFormat(string $bloquee, int $ligne = 12, array $surcharges = []): void
    {
        $this->client->request('POST', '/api/journal/csp', [], [], ['CONTENT_TYPE' => 'application/csp-report'], (string) json_encode(['csp-report' => $surcharges + [
            'document-uri' => self::PAGE,
            'violated-directive' => 'script-src-elem',
            'effective-directive' => 'script-src-elem',
            'blocked-uri' => $bloquee,
            'source-file' => 'http://localhost/assets/app-abc.js?v=3',
            'line-number' => $ligne,
            'script-sample' => 'alert(1)',
        ]]));
        self::assertResponseStatusCodeSame(204);
    }

    /** Un rapport du Reporting API, tel que Chrome l'envoie (~1 Ko avec la politique d'origine). */
    private static function rapport(int $ligne, string $echantillon = 'phpunit-csp onclick', string $page = self::PAGE): array
    {
        return [
            'age' => 12,
            'type' => 'csp-violation',
            'url' => $page,
            'user_agent' => 'Mozilla/5.0',
            'body' => [
                'documentURL' => $page,
                'effectiveDirective' => 'script-src-attr',
                'blockedURL' => 'inline',
                'sourceFile' => $page,
                'lineNumber' => $ligne,
                'columnNumber' => 4,
                'sample' => $echantillon,
                'disposition' => 'report',
                'statusCode' => 200,
                'referrer' => '',
                'originalPolicy' => str_repeat("default-src 'self'; ", 22),
            ],
        ];
    }

    /** @param list<array<string, mixed>> $rapports */
    private function lot(array $rapports): void
    {
        $this->client->request('POST', '/api/journal/csp', [], [], ['CONTENT_TYPE' => 'application/reports+json'], (string) json_encode($rapports));
        self::assertResponseStatusCodeSame(204);
    }

    public function testLAncienFormatEstEnregistreSansRequeteNiFragment(): void
    {
        $this->ancienFormat('https://evil.example/phpunit-csp.js?x=1#y');
        $e = $this->enregistrees();

        self::assertCount(1, $e);
        self::assertSame(ErreurApplicative::COTE_NAVIGATEUR, $e[0]->getCote());
        self::assertSame('CSP script-src-elem : https://evil.example/phpunit-csp.js', $e[0]->getType());
        self::assertSame('http://localhost/assets/app-abc.js', $e[0]->getFichier());
        self::assertSame('http://localhost/espacedetravail/{id}/{id}', $e[0]->getUrl(), 'Ni requête, ni fragment, ni identifiant.');
    }

    public function testLeFormatReportingApiEstEnregistre(): void
    {
        $this->lot([self::rapport(40)]);
        $e = $this->enregistrees();

        self::assertCount(1, $e);
        self::assertSame('CSP script-src-attr : inline', $e[0]->getType());
        self::assertStringContainsString('phpunit-csp onclick', (string) $e[0]->getMessage(), "L'extrait dit QUEL gestionnaire.");
        self::assertSame(40, $e[0]->getLigne());
        self::assertSame('http://localhost/espacedetravail/{id}/{id}', $e[0]->getFichier());
    }

    /** Un lot réel dépasse 8 Ko dès 8 rapports : il passe en entier. */
    public function testUnLotDeQuinzeRapportsPasse(): void
    {
        $rapports = array_map(static fn (int $i): array => self::rapport(100 + $i), range(1, 15));
        self::assertGreaterThan(8192, \strlen((string) json_encode($rapports)));
        $this->lot($rapports);

        self::assertCount(15, $this->enregistrees());
    }

    public function testUneMemeViolationEstRegroupeeAvecUnCompteur(): void
    {
        $this->ancienFormat('https://evil.example/phpunit-csp.js');
        $this->ancienFormat('https://evil.example/phpunit-csp.js?autre=1');
        $e = $this->enregistrees();

        self::assertCount(1, $e, 'Même directive, même ressource, même fichier, même ligne : une seule ligne.');
        self::assertSame(2, $e[0]->getNombreOccurrences());

        $this->ancienFormat('https://evil.example/phpunit-csp.js', 99);
        self::assertCount(2, $this->enregistrees(), 'Une autre ligne est un autre défaut.');
    }

    /** Vingt fois la même violation dans un lot : un seul comptage (le lot ne fabrique pas un palier). */
    public function testUnLotRepeteNeCompteQuUneFois(): void
    {
        $this->lot(array_fill(0, 20, self::rapport(7)));
        $e = $this->enregistrees();

        self::assertCount(1, $e);
        self::assertSame(1, $e[0]->getNombreOccurrences());
    }

    /** Un rapport se forge à volonté : jamais d'e-mail, même au palier des dix occurrences. */
    public function testJamaisDEMailDAlerte(): void
    {
        for ($i = 0; $i < 11; ++$i) {
            $this->ancienFormat('https://evil.example/phpunit-csp-palier.js');
            self::assertEmailCount(0);
        }
        self::assertSame(11, $this->enregistrees()[0]->getNombreOccurrences());
    }

    public function testLeJetonDuReleveClientNEstJamaisGarde(): void
    {
        $page = 'http://localhost/soa/' . str_repeat('a1b2', 16);
        $this->lot([self::rapport(21, 'phpunit-csp window.print()', $page)]);
        $e = $this->enregistrees();

        self::assertCount(1, $e);
        self::assertSame('http://localhost/soa/{jeton}', $e[0]->getUrl());
        self::assertSame('http://localhost/soa/{jeton}', $e[0]->getFichier());
    }

    public function testUnRapportDUnAutreSiteEstIgnore(): void
    {
        $this->ancienFormat('https://evil.example/phpunit-csp.js', 12, ['document-uri' => 'https://autre-site.example/page']);
        $this->lot([self::rapport(5, 'phpunit-csp tiers', 'https://autre-site.example/page')]);

        self::assertSame([], $this->enregistrees());
    }

    public function testLesExtensionsDuNavigateurSontIgnorees(): void
    {
        $this->ancienFormat('chrome-extension://abcdef/phpunit-csp.js');
        $this->ancienFormat('moz-extension://abcdef/phpunit-csp.js');
        // Forme réelle (CSP3) : une adresse non HTTP(S) est réduite à son schéma nu.
        $this->ancienFormat('inline', 12, ['source-file' => 'chrome-extension', 'script-sample' => 'phpunit-csp ext']);
        $this->ancienFormat('moz-extension', 12, ['script-sample' => 'phpunit-csp ext2']);
        // L'antivirus qui injecte son script dans chaque page (vu sur le poste de dev).
        $this->ancienFormat('http://me.kis.v2.scr.kaspersky-labs.com/FD126C42/main.js?phpunit-csp');

        self::assertSame([], $this->enregistrees());
    }

    public function testUnCorpsTropGrosEstIgnore(): void
    {
        $this->client->request('POST', '/api/journal/csp', [], [], ['CONTENT_TYPE' => 'application/csp-report'], (string) json_encode(['csp-report' => [
            'document-uri' => self::PAGE,
            'effective-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example/phpunit-csp.js',
            'script-sample' => str_repeat('x', 9000),
        ]]));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->enregistrees());
    }

    /** Le quota se compte par violation : un lot de deux ne passe pas quand il n'en reste qu'une. */
    public function testLeDebitEstLimiteParAdresseEtParViolation(): void
    {
        /** @var RateLimiterFactoryInterface $limiteur */
        $limiteur = static::getContainer()->get('limiter.csp_par_ip');
        $limite = $limiteur->create($this->client->getServerParameter('REMOTE_ADDR'));
        $limite->consume($limite->consume(0)->getRemainingTokens() - 1);

        $this->lot([self::rapport(1), self::rapport(2)]);
        self::assertSame([], $this->enregistrees(), 'Deux violations pour un seul jeton restant : rien.');

        $this->ancienFormat('https://evil.example/phpunit-csp.js');
        self::assertCount(1, $this->enregistrees(), 'La dernière unité sert encore.');

        $this->ancienFormat('https://evil.example/phpunit-csp-2.js');
        self::assertCount(1, $this->enregistrees(), 'Au-delà du quota, rien.');
    }

    /** Un numéro de ligne hors de la colonne fermerait l'EntityManager et perdrait le lot. */
    public function testUneLigneAberranteNePerdPasLeRapport(): void
    {
        $this->lot([self::rapport(3000000000)]);
        $e = $this->enregistrees();

        self::assertCount(1, $e);
        self::assertNull($e[0]->getLigne());
    }

    public function testUnCorpsIllisibleNeCassePasLaRoute(): void
    {
        $this->client->request('POST', '/api/journal/csp', [], [], ['CONTENT_TYPE' => 'application/csp-report'], '{pas du json');

        self::assertResponseStatusCodeSame(204);
    }
}
