<?php

namespace App\Tests\Console;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tableau de bord de la Console : points d'entrée AJAX des blocs (KPIs, revenu,
 * ventes, entreprises, utilisateurs) et leurs fragments. Vérifie l'accès, le
 * rendu et la présence des trois modes du bloc « Revenu des ventes »
 * (Par mois / Par paquet / Par pays).
 */
class DashboardBlocksTest extends WebTestCase
{
    private const ADMIN = 'phpunit-db-admin@test.local';
    private const USER  = 'phpunit-db-user@test.local';
    private const PASSWORD = 'Test1234!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        foreach ([self::ADMIN => ['ROLE_ADMIN'], self::USER => []] as $email => $roles) {
            $u = new Utilisateur();
            $u->setEmail($email);
            $u->setNom('PHPUnit ' . $email);
            $u->setVerified(true);
            $u->setLocale('fr');
            $u->setRoles($roles);
            $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
            $em->persist($u);
        }
        $em->flush();
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
        $this->em()->getConnection()->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:e)',
            ['e' => [self::ADMIN, self::USER]],
            ['e' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    /** @return string[] */
    private function blockUrls(): array
    {
        return [
            '/console/dashboard/block/kpis',
            '/console/dashboard/block/revenue',
            '/console/dashboard/block/ventes',
            '/console/dashboard/ventes-fragment',
            '/console/dashboard/block/entreprises',
            '/console/dashboard/entreprises-fragment',
            '/console/dashboard/block/utilisateurs',
            '/console/dashboard/utilisateurs-fragment',
        ];
    }

    public function testAllBlockEndpointsRespondForAdmin(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        foreach ($this->blockUrls() as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful(sprintf('Le bloc %s doit répondre 200 pour un agent.', $url));
        }
    }

    public function testBlockEndpointsForbiddenForRegularUser(): void
    {
        $this->client->loginUser($this->user(self::USER));

        foreach ($this->blockUrls() as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseStatusCodeSame(403, sprintf('Le bloc %s doit être interdit à un utilisateur ordinaire.', $url));
        }
    }

    public function testRevenueBlockExposesThreeModes(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/dashboard/block/revenue');
        $this->assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('data-mode="mois"', $html);
        $this->assertStringContainsString('data-mode="pack"', $html);
        $this->assertStringContainsString('data-mode="pays"', $html, 'Le nouveau mode « Par pays » doit être présent.');
    }

    public function testDashboardPageHasThreeToggleButtons(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        $crawler = $this->client->request('GET', '/console');
        $this->assertResponseIsSuccessful();

        // Trois boutons de bascule dans le bloc « Revenu des ventes ».
        $modes = $crawler->filter('button.db-task-toggle[data-chart-modes-target="tab"]')
            ->each(static fn ($node) => $node->attr('data-mode'));

        $this->assertContains('mois', $modes);
        $this->assertContains('pack', $modes);
        $this->assertContains('pays', $modes);
    }
}
