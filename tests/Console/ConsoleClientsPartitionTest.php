<?php

namespace App\Tests\Console;

use App\Entity\Utilisateur;
use App\Services\ConsoleStatsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Console JS Brokers : la rubrique « Clients » liste les comptes en mode payant
 * (paidTokens > 0) et « Utilisateurs » les comptes gratuits (paidTokens = 0).
 * Les deux listes sont disjointes ; aucune entité assuré (Client) n'intervient.
 */
class ConsoleClientsPartitionTest extends WebTestCase
{
    private const ADMIN = 'phpunit-part-admin@test.local';
    private const PAYANT = 'phpunit-part-payant@test.local';
    private const GRATUIT = 'phpunit-part-gratuit@test.local';
    // Agent JS Brokers qui a aussi acheté des jetons : c'est un client (paidTokens > 0)
    // malgré son rôle. Verrouille le fait que « client » ne dépend que du solde payant.
    private const ADMIN_PAYANT = 'phpunit-part-admin-payant@test.local';
    private const PASSWORD = 'Test1234!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        // ADMIN (agent), un client payant, un utilisateur gratuit.
        $specs = [
            self::ADMIN        => ['roles' => ['ROLE_ADMIN'],       'paid' => 0],
            self::PAYANT       => ['roles' => [],                   'paid' => 5000],
            self::GRATUIT      => ['roles' => [],                   'paid' => 0],
            self::ADMIN_PAYANT => ['roles' => ['ROLE_SUPER_ADMIN'], 'paid' => 10960],
        ];
        foreach ($specs as $email => $spec) {
            $u = new Utilisateur();
            $u->setEmail($email);
            $u->setNom('PHPUnit ' . $email);
            $u->setVerified(true);
            $u->setLocale('fr');
            $u->setRoles($spec['roles']);
            $u->setPaidTokens($spec['paid']);
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
            ['e' => [self::ADMIN, self::PAYANT, self::GRATUIT, self::ADMIN_PAYANT]],
            ['e' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    public function testClientsListShowsOnlyPayingUsers(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));
        $this->client->request('GET', '/console/clients');

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString(self::PAYANT, $html, 'Le client payant doit apparaître dans les Clients.');
        $this->assertStringContainsString(self::ADMIN_PAYANT, $html, 'Un agent qui a un solde payant est aussi un client.');
        $this->assertStringNotContainsString(self::GRATUIT, $html, 'Un compte gratuit ne doit pas apparaître dans les Clients.');
    }

    public function testPayingAgentIsCountedAsClient(): void
    {
        // Régression : un super-admin avec solde prépayé > 0 doit être compté
        // comme client, indépendamment de son rôle.
        $repo = $this->em()->getRepository(Utilisateur::class);
        $admin = $this->user(self::ADMIN_PAYANT);

        $this->assertGreaterThan(0, $admin->getPaidTokens());
        $this->assertGreaterThanOrEqual(2, $repo->countClients(), 'Les deux comptes payants (régulier + agent) sont des clients.');
    }

    public function testUtilisateursListShowsOnlyFreeUsers(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));
        $this->client->request('GET', '/console/utilisateurs');

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString(self::GRATUIT, $html, 'Le compte gratuit doit apparaître dans les Utilisateurs.');
        $this->assertStringNotContainsString(self::PAYANT, $html, 'Un client payant ne doit pas apparaître dans les Utilisateurs.');
        $this->assertStringNotContainsString(self::ADMIN_PAYANT, $html, 'Un agent payant n\'est pas un utilisateur gratuit.');
    }

    public function testKpisCountPayingUsersAsClients(): void
    {
        // Les KPIs distinguent comptes payants (clients) et gratuits (utilisateurs),
        // indépendamment des assurés (entité Client).
        $stats = static::getContainer()->get(ConsoleStatsProvider::class);
        $kpis = $stats->getKpis();

        $this->assertGreaterThanOrEqual(1, $kpis['nbClients'], 'Le client payant doit être compté dans nbClients.');
        $this->assertGreaterThanOrEqual(1, $kpis['nbUsers'], 'Le compte gratuit doit être compté dans nbUsers.');

        $repo = $this->em()->getRepository(Utilisateur::class);
        $this->assertSame($repo->countClients(), $kpis['nbClients']);
        $this->assertSame($repo->countRegularUsers(), $kpis['nbUsers']);
    }
}
