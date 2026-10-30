<?php

namespace App\Tests\Nouveautes;

use App\Entity\Utilisateur;
use App\Enum\Departement;
use App\Services\VersionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * AUCUN MESSAGE DE COMMIT N'EST PUBLIÉ HORS DE L'ÉQUIPE (Serge, 2026-10-30).
 *
 * La page « Nouveautés » (route app_changelog) publiait à tous — sans connexion — le premier
 * paragraphe des commits des trente derniers jours, et le menu de l'espace de travail montrait
 * le dernier en infobulle. Ces messages décrivent nos corrections, failles comprises
 * (cloisonnement entre cabinets, écritures sans droit…) : une fuite d'information exploitable.
 *
 * Désormais : 404 pour tout visiteur et tout utilisateur d'un cabinet ; seule l'équipe Joseara
 * (agents de la console, ROLE_ADMIN) la lit, et la page n'est jamais indexée. Aucun lien n'y
 * mène hors de la console ; aucun message de commit dans le menu. Les utilisateurs ne voient
 * que des notes de version (jsbrokers-dev §6).
 */
class NouveautesPageTest extends WebTestCase
{
    private const AGENT = 'phpunit-nouveautes-agent@test.local';
    private const COURTIER = 'phpunit-nouveautes-courtier@test.local';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function cleanUp(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM utilisateur WHERE email IN (?, ?)', [self::AGENT, self::COURTIER]);
        $this->em()->clear();
    }

    private function utilisateur(string $email, array $roles): Utilisateur
    {
        $u = (new Utilisateur())->setEmail($email)->setNom('PHPUnit')->setVerified(true)->setPassword('x');
        $u->setRoles($roles);
        if ($roles !== []) {
            $u->setDepartement(Departement::DIRECTION);
        }
        $this->em()->persist($u);
        $this->em()->flush();

        return $u;
    }

    public function testUnVisiteurNeLaTrouvePas(): void
    {
        $this->client->request('GET', '/nouveautes?lang=fr');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/nouveautes?lang=en');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnUtilisateurDUnCabinetNeLaTrouvePasNonPlus(): void
    {
        $this->client->loginUser($this->utilisateur(self::COURTIER, []));
        $this->client->request('GET', '/nouveautes?lang=fr');
        self::assertResponseStatusCodeSame(404);
    }

    public function testLEquipeJosearaLaLitSansQuElleSoitIndexee(): void
    {
        $this->client->loginUser($this->utilisateur(self::AGENT, ['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/nouveautes?lang=fr');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame(1, $crawler->filter('meta[name="robots"][content*="noindex"]')->count());
    }

    public function testAucunLienPublicNyMene(): void
    {
        foreach (['/conditions-utilisation?lang=fr', '/login', '/'] as $page) {
            $this->client->request('GET', $page);
            self::assertStringNotContainsString('/nouveautes', (string) $this->client->getResponse()->getContent(), "$page mène encore aux nouveautés.");
        }
    }

    /** Le numéro de version reste affiché — sans lien ni message de commit. */
    public function testLaVersionResteAfficheeSansLien(): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $version = $crawler->filter('.auth__brand-version');
        self::assertSame(1, $version->count());
        self::assertSame('p', $version->nodeName(), 'La version est un texte, plus un lien.');
        $attendue = static::getContainer()->get(VersionService::class)->getVersion();
        self::assertStringContainsString($attendue, $version->text());
        self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $attendue);
        [, $mineur] = explode('.', $attendue, 2);
        self::assertLessThanOrEqual(99, (int) $mineur, 'Après x.99 vient (x+1).00.');
    }

    /** Le menu de l'espace de travail ne montre plus le dernier commit en infobulle. */
    public function testLeMenuNeMontrePlusLeDernierCommit(): void
    {
        $menu = (string) file_get_contents(__DIR__ . '/../../templates/components/_interactive_menu.html.twig');
        self::assertStringNotContainsString('app_last_commit', $menu);
        self::assertStringNotContainsString("path('app_changelog')", $menu);
    }
}
