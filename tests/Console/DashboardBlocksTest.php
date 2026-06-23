<?php

namespace App\Tests\Console;

use App\Entity\Coupon;
use App\Entity\TaxeVente;
use App\Entity\TokenPurchase;
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
    private const COUPON = 'PHPUNIT-DB';
    private const TAXE = 'PHPUNIT-DB-TVA';
    private const VENTE_REF = 'PHPUNIT-DB-SALE';

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

        // Coupon de test : alimente le bloc « Derniers coupons ».
        $coupon = new Coupon();
        $coupon->setCode(self::COUPON);
        $coupon->setType(Coupon::TYPE_PERCENT);
        $coupon->setValeur(10);
        $coupon->setDateDebut(new \DateTimeImmutable('-1 day'));
        $coupon->setDateFin(new \DateTimeImmutable('+1 day'));
        $coupon->setActif(true);
        $em->persist($coupon);

        // Taxe de test : alimente le bloc « Fiscalité ».
        $taxe = new TaxeVente();
        $taxe->setCode(self::TAXE);
        $taxe->setLibelle('TVA de test');
        $taxe->setAutoriteNom('Direction Générale des Impôts');
        $taxe->setAutoriteAbreviation('DGI');
        $taxe->setTaux('16');
        $taxe->setActif(true);
        $em->persist($taxe);

        $em->flush();

        // Vente de test : alimente le bloc « Dernières ventes » (sinon thead absent).
        $vente = new TokenPurchase();
        $vente->setUtilisateur($this->user(self::USER));
        $vente->setPack('starter');
        $vente->setTokens(500);
        $vente->setMontantUsd(116.0);
        $vente->setReference(self::VENTE_REF);
        $em->persist($vente);

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
            ['e' => [self::ADMIN, self::USER]],
            ['e' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement('DELETE FROM coupon WHERE code = :c', ['c' => self::COUPON]);
        $conn->executeStatement('DELETE FROM taxe_vente WHERE code = :c', ['c' => self::TAXE]);
        $conn->executeStatement('DELETE FROM token_purchase WHERE reference = :r', ['r' => self::VENTE_REF]);
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
            '/console/dashboard/block/clients',
            '/console/dashboard/clients-fragment',
            '/console/dashboard/block/utilisateurs',
            '/console/dashboard/utilisateurs-fragment',
            '/console/dashboard/block/coupons',
            '/console/dashboard/coupons-fragment',
            '/console/dashboard/block/plans',
            '/console/dashboard/block/taxes',
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

    public function testCouponsBlockShowsCoupon(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/dashboard/block/coupons');
        $this->assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString(self::COUPON, $html, 'Le bloc « Derniers coupons » doit lister le coupon de test.');
    }

    public function testPlansBlockListsPackages(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/dashboard/block/plans');
        $this->assertResponseIsSuccessful();

        // Au moins un palier tarifaire est listé : les paquets par défaut
        // (TokenPricing::PACKS) servent de repli, donc aucun fixture n'est requis.
        $rows = $this->client->getCrawler()->filter('table.cs-table tbody tr');
        $this->assertGreaterThan(0, $rows->count(), 'Le bloc « Plans tarifaires » doit lister au moins un paquet.');
    }

    public function testKpisBlockExposesConversionAndPreTaxRevenue(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/dashboard/block/kpis');
        $this->assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Taux de conversion', $html, 'La grille de KPIs doit afficher le taux de conversion.');
        $this->assertStringContainsString('Revenu hors taxe', $html, 'La grille de KPIs doit afficher le revenu hors taxe.');
    }

    public function testKpisBlockExposesOneCardPerTax(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/dashboard/block/kpis');
        $this->assertResponseIsSuccessful();

        // Une carte KPI dédiée par taxe active (libellée « Taxe <code> »).
        $this->assertStringContainsString('Taxe ' . self::TAXE, (string) $this->client->getResponse()->getContent(), 'La grille de KPIs doit afficher une carte par taxe active.');
    }

    public function testTaxesBlockShowsConfiguredTaxAndSummary(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/dashboard/block/taxes');
        $this->assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString(self::TAXE, $html, 'Le bloc « Fiscalité » doit lister la taxe de test.');
        $this->assertStringContainsString('DGI', $html, "L'abréviation de l'autorité fiscale doit apparaître.");
        $this->assertStringContainsString('Revenu hors taxe', $html, 'La synthèse doit afficher le revenu hors taxe.');
    }

    public function testPlansBlockExposesPreTaxPriceColumn(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/dashboard/block/plans');
        $this->assertResponseIsSuccessful();

        $this->assertStringContainsString('Prix HT', (string) $this->client->getResponse()->getContent(), 'Le bloc « Plans tarifaires » doit exposer la colonne « Prix HT ».');
    }

    public function testVentesBlockExposesPreTaxAndTaxColumns(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/dashboard/block/ventes');
        $this->assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Revenu HT', $html, 'Le bloc « Dernières ventes » doit exposer la colonne « Revenu HT ».');
        $this->assertStringContainsString('Taxes', $html, 'Le bloc « Dernières ventes » doit exposer la colonne « Taxes ».');
    }
}
