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

        // Même pattern pour les poids d'écriture : collection + champ caché + dialogue
        // (avec sélecteur d'entité). Plus aucun textarea JSON brut sur la page.
        $this->assertCount(1, $crawler->filter('[data-controller="weights-editor"]'));
        $this->assertCount(1, $crawler->filter('input[type="hidden"][name="plan_tarifaire[writeWeightsJson]"]'));
        $this->assertCount(1, $crawler->filter('dialog[data-weights-editor-target="dialog"] select[data-weights-editor-target="fieldEntity"]'));
        $this->assertCount(0, $crawler->filter('textarea[name="plan_tarifaire[writeWeightsJson]"]'));

        // La boîte de confirmation de suppression vient du layout global (base.html.twig)
        // et NE DOIT PAS être dupliquée par la page : une seule instance, sinon deux
        // modales s'ouvriraient en même temps.
        $this->assertCount(1, $crawler->filter('#confirmation-dialog-modal'),
            'Le dialogue de confirmation doit être présent en une seule instance.');
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

    /** Un poids d'écriture saisi via la collection est persisté et relayé par le service. */
    public function testSuperAdminSavesWriteWeight(): void
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
        // Soumission telle que produite par le contrôleur Stimulus (clé = FQCN).
        $form['plan_tarifaire[writeWeightsJson]'] = json_encode([
            'App\\Entity\\Cotation' => 75,
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/console/plan-tarifaire');

        $params = static::getContainer()->get(ParametresTokenService::class);
        $params->refresh();
        $this->assertSame(75, $params->weightFor('App\\Entity\\Cotation'));
        // Une entité non configurée retombe sur le poids par défaut.
        $this->assertSame(5, $params->weightFor('App\\Entity\\Piste'));
    }

    /**
     * Modernisation : le catalogue d'entités facturables couvre désormais 100 % des
     * entités du workspace. Le sélecteur de l'éditeur de poids d'écriture (attribut
     * data-weights-editor-labels-value) doit donc exposer les entités autrefois
     * absentes — dont « Chargement sur prime », citée en exemple par le besoin.
     */
    public function testWeightEditorCatalogCoversAllWorkspaceEntities(): void
    {
        $this->client->loginUser($this->user(self::SUPER));

        $crawler = $this->client->request('GET', '/console/plan-tarifaire');
        $this->assertResponseIsSuccessful();

        $labels = $crawler->filter('[data-controller="weights-editor"]')
            ->attr('data-weights-editor-labels-value');
        $this->assertNotNull($labels);

        $catalogue = json_decode($labels, true);
        $this->assertIsArray($catalogue);

        // Entités désormais facturables (auparavant hors catalogue) : elles doivent
        // toutes être proposées au paramétrage du poids d'écriture.
        $attendues = [
            \App\Entity\ChargementPourPrime::class, // « chargement sur prime » (exemple du besoin)
            \App\Entity\Chargement::class,
            \App\Entity\Tranche::class,
            \App\Entity\Monnaie::class,
            \App\Entity\Taxe::class,
            \App\Entity\AutoriteFiscale::class,
            \App\Entity\RevenuPourCourtier::class,
            \App\Entity\TypeRevenu::class,
            \App\Entity\Operation::class,
            \App\Entity\Article::class,
            \App\Entity\Groupe::class,
            \App\Entity\ConditionPartage::class,
            \App\Entity\ModelePieceSinistre::class,
            \App\Entity\PieceSinistre::class,
            \App\Entity\NotificationSinistre::class,
            \App\Entity\OffreIndemnisationSinistre::class,
            \App\Entity\Classeur::class,
            \App\Entity\Invite::class,
            \App\Entity\RolesEnFinance::class,
            \App\Entity\RolesEnProduction::class,
            \App\Entity\RolesEnSinistre::class,
            \App\Entity\RolesEnMarketing::class,
            \App\Entity\RolesEnAdministration::class,
        ];
        foreach ($attendues as $fqcn) {
            $this->assertArrayHasKey($fqcn, $catalogue, sprintf('%s doit être proposée au paramétrage du poids d\'écriture.', $fqcn));
            $this->assertNotSame('', (string) $catalogue[$fqcn], sprintf('%s doit avoir un libellé.', $fqcn));
        }
    }

    /**
     * Bout en bout : un poids d'écriture défini sur une entité auparavant NON
     * paramétrable (« Chargement sur prime ») est persisté et effectivement appliqué
     * par le service de facturation (weightFor) — la logique de métrage étant générique.
     */
    public function testSuperAdminBillsWriteWeightOnPreviouslyUnavailableEntity(): void
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
        $form['plan_tarifaire[writeWeightsJson]'] = json_encode([
            \App\Entity\ChargementPourPrime::class => 40,
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/console/plan-tarifaire');

        $params = static::getContainer()->get(ParametresTokenService::class);
        $params->refresh();
        $this->assertSame(40, $params->weightFor(\App\Entity\ChargementPourPrime::class));
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

    /**
     * La page publique « Fonctionnement des tokens » tire toutes ses valeurs du plan
     * tarifaire édité en Console : libellé de paquet, poids d'écriture par entité,
     * allocation gratuite.
     */
    public function testTokensInfoPageReflectsConsolePlan(): void
    {
        $repository = static::getContainer()->get(PlateformeParametresRepository::class);
        $params = $repository->getSingleton();
        $params->setPacks(['decouverte' => ['label' => 'Découverte Promo', 'tokens' => 7000, 'price' => 7]]);
        $params->setWriteWeights(['App\\Entity\\Cotation' => 321]);
        $params->setFreeAllowance(4242);
        $this->em()->flush();
        static::getContainer()->get(ParametresTokenService::class)->refresh();

        $this->client->request('GET', '/fonctionnement-tokens');
        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Découverte Promo', $content);   // libellé éditable du paquet
        $this->assertStringContainsString('321', $content);                 // poids d'écriture personnalisé
        $this->assertStringContainsString('Cotation', $content);            // libellé d'entité résolu
        $this->assertStringContainsString('4 242', $content);               // allocation gratuite éditée
    }
}
