<?php

namespace App\Tests\Console;

use App\Entity\Utilisateur;
use App\Token\ParametresTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Console JS Brokers : contrôle d'accès (ROLE_ADMIN / ROLE_SUPER_ADMIN), rendu
 * des pages, création d'un collaborateur avec notification, édition du plan
 * tarifaire et son repli sur les constantes après nettoyage.
 */
class ConsoleAccessTest extends WebTestCase
{
    private const USER  = 'phpunit-cs-user@test.local';
    private const ADMIN = 'phpunit-cs-admin@test.local';
    private const SUPER = 'phpunit-cs-super@test.local';
    private const NEW_COLLAB = 'phpunit-cs-newcollab@test.local';
    private const PASSWORD = 'Test1234!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        $roles = [
            self::USER  => [],
            self::ADMIN => ['ROLE_ADMIN'],
            self::SUPER => ['ROLE_SUPER_ADMIN'],
        ];
        foreach ($roles as $email => $r) {
            $u = new Utilisateur();
            $u->setEmail($email);
            $u->setNom('PHPUnit ' . $email);
            $u->setVerified(true);
            $u->setRoles($r);
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
        $conn = $this->em()->getConnection();
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:e)',
            ['e' => [self::USER, self::ADMIN, self::SUPER, self::NEW_COLLAB]],
            ['e' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        // Réinitialise le plan tarifaire : on repart des constantes (repli) pour
        // ne pas influencer les autres tests (ex. allocation gratuite = 1000).
        $conn->executeStatement('DELETE FROM plateforme_parametres');
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    public function testRegularUserIsForbidden(): void
    {
        $this->client->loginUser($this->user(self::USER));
        $this->client->request('GET', '/console');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/console');
        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAdminReachesDashboardAndLists(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        foreach ([
            '/console',
            '/console/collaborateurs',
            '/console/utilisateurs',
            '/console/clients',
            '/console/entreprises',
            '/console/ventes',
            '/console/coupons',
            '/console/taxes',
        ] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful(sprintf('La page %s doit répondre 200 pour un agent.', $url));
        }
    }

    public function testRubricTitlesShowIconPastille(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        // Chaque rubrique (liste / tableau de bord) doit afficher la pastille cobalt
        // à icône devant son titre (.cs-head-title > .cs-head-icon), pour l'unité
        // visuelle avec les formulaires. La pastille porte une <svg> résolue via
        // l'alias `pageIcon` passé par le contrôleur.
        foreach ([
            '/console',
            '/console/collaborateurs',
            '/console/utilisateurs',
            '/console/clients',
            '/console/entreprises',
            '/console/ventes',
            '/console/coupons',
            '/console/taxes',
        ] as $url) {
            $crawler = $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful(sprintf('La page %s doit répondre 200.', $url));
            $this->assertSame(
                1,
                $crawler->filter('.cs-head .cs-head-title .cs-head-icon svg')->count(),
                sprintf('La pastille d\'icône doit être présente devant le titre de %s.', $url)
            );
        }
    }

    public function testPlanTarifaireForbiddenForPlainAdmin(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));
        $this->client->request('GET', '/console/plan-tarifaire');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testPlanTarifaireAllowedForSuperAdmin(): void
    {
        $this->client->loginUser($this->user(self::SUPER));
        $this->client->request('GET', '/console/plan-tarifaire');
        $this->assertResponseIsSuccessful();
    }

    public function testSuperAdminCreatesCollaboratorAndNotifiesAgents(): void
    {
        $this->client->loginUser($this->user(self::SUPER));

        $crawler = $this->client->request('GET', '/console/collaborateurs/new');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['collaborateur[nom]'] = 'Nouvel Agent';
        $form['collaborateur[email]'] = self::NEW_COLLAB;
        $form['collaborateur[plainPassword]'] = self::PASSWORD;
        $this->client->submit($form);

        $this->assertResponseRedirects('/console/collaborateurs');

        // Le collaborateur est créé en tant qu'agent vérifié.
        $this->em()->clear();
        $created = $this->user(self::NEW_COLLAB);
        $this->assertNotNull($created);
        $this->assertTrue($created->isVerified());
        $this->assertContains('ROLE_ADMIN', $created->getRoles());

        // Tous les agents (y compris le nouveau) reçoivent la notification.
        $nbAgents = count($this->em()->getRepository(Utilisateur::class)->findAgents());
        $this->assertQueuedEmailCount($nbAgents);
    }

    public function testSuperAdminEditsPricingPlanAndServiceReflectsIt(): void
    {
        $this->client->loginUser($this->user(self::SUPER));

        $crawler = $this->client->request('GET', '/console/plan-tarifaire');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['plan_tarifaire[freeAllowance]'] = '2500';
        $form['plan_tarifaire[freeWindowHours]'] = '8';
        $form['plan_tarifaire[readWeight]'] = '2';
        $form['plan_tarifaire[defaultWriteWeight]'] = '5';
        $form['plan_tarifaire[usdPerToken]'] = '0.001';
        $form['plan_tarifaire[packsJson]'] = json_encode([
            'intermediaire' => ['tokens' => 10000, 'price' => 9],
            'professionnel' => ['tokens' => 50000, 'price' => 40],
        ]);
        $form['plan_tarifaire[writeWeightsJson]'] = json_encode(['App\\Entity\\Entreprise' => 250]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/console/plan-tarifaire');

        // Le service de tarification reflète les nouvelles valeurs.
        $params = static::getContainer()->get(ParametresTokenService::class);
        $params->refresh();
        $this->assertSame(2500, $params->freeAllowance());
        $this->assertSame(250, $params->weightFor('App\\Entity\\Entreprise'));
        $this->assertSame(9, $params->pack('intermediaire')['price']);
    }
}
