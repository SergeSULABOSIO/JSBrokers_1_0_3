<?php

namespace App\Tests\Console;

use App\Entity\Utilisateur;
use App\Repository\PlateformeParametresRepository;
use App\Token\ParametresTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Édition des « Paquets prépayés » du plan tarifaire (Console).
 *
 * La collection est éditée via une boîte de dialogue côté client, mais le contrat
 * serveur est inchangé : le champ caché plan_tarifaire[packsJson] porte le JSON
 * { "<clé>": { label, tokens, price } } décodé et persisté tel quel. Ces tests
 * couvrent ce contrat (persistance du libellé) et la rétro-compatibilité du repli
 * de libellé (ucfirst(clé)) sur la page d'achat.
 */
class ConsolePlanTarifairePacksTest extends WebTestCase
{
    private const USER  = 'phpunit-packs-user@test.local';
    private const SUPER = 'phpunit-packs-super@test.local';
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
            ['e' => [self::USER, self::SUPER]],
            ['e' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        // Le plan tarifaire est un singleton : on le réinitialise pour repartir
        // des constantes (repli) et ne pas influencer les autres tests.
        $conn->executeStatement('DELETE FROM plateforme_parametres');
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    /** La page d'édition rend bien la collection éditable (contrôleur + champ caché). */
    public function testEditPageRendersPacksCollection(): void
    {
        $this->client->loginUser($this->user(self::SUPER));

        $crawler = $this->client->request('GET', '/console/plan-tarifaire');
        $this->assertResponseIsSuccessful();

        // Le conteneur de collection et son contrôleur Stimulus sont présents…
        $this->assertCount(1, $crawler->filter('[data-controller="packs-editor"]'));
        // …ainsi que le champ caché (source de vérité soumise) et la boîte de dialogue.
        $this->assertCount(1, $crawler->filter('input[type="hidden"][name="plan_tarifaire[packsJson]"]'));
        $this->assertCount(1, $crawler->filter('dialog[data-packs-editor-target="dialog"]'));
    }

    /** Un paquet avec libellé est persisté tel quel et relayé par le service. */
    public function testSuperAdminSavesPackWithLabel(): void
    {
        $this->client->loginUser($this->user(self::SUPER));

        $crawler = $this->client->request('GET', '/console/plan-tarifaire');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['plan_tarifaire[freeAllowance]'] = '1000';
        $form['plan_tarifaire[freeWindowHours]'] = '8';
        $form['plan_tarifaire[readWeight]'] = '2';
        $form['plan_tarifaire[defaultWriteWeight]'] = '5';
        $form['plan_tarifaire[usdPerToken]'] = '0.001';
        // Soumission telle que produite par le contrôleur Stimulus (clé stable + libellé).
        $form['plan_tarifaire[packsJson]'] = json_encode([
            'demarrage' => ['label' => 'Démarrage', 'tokens' => 5000, 'price' => 5],
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/console/plan-tarifaire');

        $params = static::getContainer()->get(ParametresTokenService::class);
        $params->refresh();

        $pack = $params->pack('demarrage');
        $this->assertNotNull($pack);
        $this->assertSame('Démarrage', $pack['label']);
        $this->assertSame(5000, $pack['tokens']);
        $this->assertSame(5, $pack['price']);
    }

    /**
     * Rétro-compatibilité : sur la page d'achat, un paquet avec libellé affiche ce
     * libellé ; un paquet sans libellé (format historique) retombe sur ucfirst(clé).
     */
    public function testPurchaseFormUsesLabelWithFallback(): void
    {
        // Prépare des paquets : l'un avec libellé, l'autre sans (format historique).
        $repository = static::getContainer()->get(PlateformeParametresRepository::class);
        $params = $repository->getSingleton();
        $params->setPacks([
            'premium' => ['label' => 'Premium VIP', 'tokens' => 100000, 'price' => 80],
            'legacy'  => ['tokens' => 3000, 'price' => 3],
        ]);
        $this->em()->flush();
        static::getContainer()->get(ParametresTokenService::class)->refresh();

        $this->client->loginUser($this->user(self::USER));
        $this->client->request('GET', '/admin/tokens/buy');
        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        // Libellé éditable affiché tel quel…
        $this->assertStringContainsString('Premium VIP', $content);
        // …et repli ucfirst(clé) pour le paquet historique sans libellé.
        $this->assertStringContainsString('Legacy', $content);
    }

    /**
     * Cœur de l'exigence : les tarifs affichés sur le portail public viennent du
     * paramétrage de la Console. Un paquet défini en Console apparaît sur la
     * vitrine ; un paquet codé en dur d'autrefois n'y figure plus.
     */
    public function testPublicPortalReflectsConsolePacks(): void
    {
        $repository = static::getContainer()->get(PlateformeParametresRepository::class);
        $params = $repository->getSingleton();
        $params->setPacks([
            'demarrage' => ['label' => 'Démarrage Promo', 'tokens' => 7000, 'price' => 7],
        ]);
        $this->em()->flush();
        static::getContainer()->get(ParametresTokenService::class)->refresh();

        // Vitrine publique (accès anonyme).
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        // Le paquet paramétré en Console apparaît (nom en capitales sur la carte)…
        $this->assertStringContainsString('DÉMARRAGE PROMO', $content);
        $this->assertStringContainsString('7 000', $content);
        // …et l'ancienne carte codée en dur n'existe plus.
        $this->assertStringNotContainsString('INTERMÉDIAIRE', $content);
    }
}
