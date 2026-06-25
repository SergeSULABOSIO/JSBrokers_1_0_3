<?php

namespace App\Tests\Console;

use App\Entity\Charge;
use App\Entity\Depense;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Console JS Brokers : CRUD des rubriques « Charges » (types de charges OHADA) et
 * « Dépenses » (sorties de fonds), plus exposition des KPI Finance/SaaS et du bloc
 * « Dernières dépenses » sur le tableau de bord. Vérifie l'accès (agent vs
 * utilisateur), la création/édition/suppression via formulaires et la persistance.
 */
class ConsoleChargeDepenseTest extends WebTestCase
{
    private const ADMIN = 'phpunit-charge-admin@test.local';
    private const USER  = 'phpunit-charge-user@test.local';
    private const PASSWORD = 'Test1234!';
    private const CHARGE_CODE = 'PHPUNIT-CHARGE';
    private const DEPENSE_REF = 'PHPUNIT-DEP-001';

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
        $conn = $this->em()->getConnection();
        // Dépenses d'abord (FK vers charge), puis les charges de test, puis les comptes.
        $conn->executeStatement('DELETE d FROM depense d JOIN charge c ON d.charge_id = c.id WHERE c.code LIKE :c', ['c' => 'PHPUNIT-%']);
        $conn->executeStatement('DELETE FROM charge WHERE code LIKE :c', ['c' => 'PHPUNIT-%']);
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:e)',
            ['e' => [self::ADMIN, self::USER]],
            ['e' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function charge(): ?Charge
    {
        return $this->em()->getRepository(Charge::class)->findOneBy(['code' => self::CHARGE_CODE]);
    }

    /** Crée et persiste un type de charge de test (utilisé par les dépenses). */
    private function createChargeFixture(): Charge
    {
        $charge = new Charge();
        $charge->setCode(self::CHARGE_CODE);
        $charge->setLibelle('Hébergement infrastructure');
        $charge->setCompteOhada('62');
        $charge->setDestination(Charge::DEST_COUT_DIRECT);
        $charge->setComportement(Charge::COMPORTEMENT_VARIABLE);
        $charge->setPeriodicite(Charge::PERIODICITE_MENSUELLE);
        $charge->setActif(true);
        $this->em()->persist($charge);
        $this->em()->flush();

        return $charge;
    }

    public function testRegularUserIsForbiddenOnCharges(): void
    {
        $this->client->loginUser($this->user(self::USER));
        $this->client->request('GET', '/console/charges');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testRegularUserIsForbiddenOnDepenses(): void
    {
        $this->client->loginUser($this->user(self::USER));
        $this->client->request('GET', '/console/depenses');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCreatesEditsAndDeletesCharge(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        // Création.
        $crawler = $this->client->request('GET', '/console/charges/new');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['charge[code]'] = self::CHARGE_CODE;
        $form['charge[libelle]'] = 'Salaires équipe';
        $form['charge[compteOhada]'] = '66';
        $form['charge[destination]'] = Charge::DEST_EXPLOITATION;
        $form['charge[comportement]'] = Charge::COMPORTEMENT_FIXE;
        $form['charge[periodicite]'] = Charge::PERIODICITE_MENSUELLE;
        $form['charge[montantBudgeteMensuel]'] = '3000';
        $form['charge[actif]'] = true;
        $this->client->submit($form);

        $this->assertResponseRedirects('/console/charges');

        $this->em()->clear();
        $charge = $this->charge();
        $this->assertNotNull($charge, 'La charge doit être persistée.');
        $this->assertSame('66', $charge->getCompteOhada());
        $this->assertSame(Charge::DEST_EXPLOITATION, $charge->getDestination());
        $this->assertEqualsWithDelta(3000.0, $charge->getMontantBudgeteMensuelFloat(), 0.001);
        $this->assertTrue($charge->isActif());

        // Édition : on change la destination.
        $crawler = $this->client->request('GET', '/console/charges/' . $charge->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form')->form();
        $form['charge[destination]'] = Charge::DEST_ACQUISITION;
        $this->client->submit($form);
        $this->assertResponseRedirects('/console/charges');

        $this->em()->clear();
        $this->assertSame(Charge::DEST_ACQUISITION, $this->charge()->getDestination());

        // Suppression (CSRF).
        $id = $this->charge()->getId();
        $this->client->request('GET', '/console/charges');
        $token = $this->client->getCrawler()
            ->filter('form[action$="/console/charges/' . $id . '"] input[name="_token"]')
            ->attr('value');

        $this->client->request('POST', '/console/charges/' . $id, ['_token' => $token]);
        $this->assertResponseRedirects('/console/charges');

        $this->em()->clear();
        $this->assertNull($this->charge(), 'La charge doit être supprimée.');
    }

    public function testAdminCreatesEditsAndDeletesDepense(): void
    {
        $charge = $this->createChargeFixture();
        $this->client->loginUser($this->user(self::ADMIN));

        // Création.
        $crawler = $this->client->request('GET', '/console/depenses/new');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['depense[charge]'] = (string) $charge->getId();
        $form['depense[dateDepense]'] = '2026-06-20';
        $form['depense[montant]'] = '250.00';
        $form['depense[devise]'] = 'USD';
        $form['depense[beneficiaire]'] = 'Hébergeur Cloud';
        $form['depense[reference]'] = self::DEPENSE_REF;
        $form['depense[moyenPaiement]'] = Depense::MOYEN_BANQUE;
        $form['depense[statut]'] = Depense::STATUT_PAYEE;
        $form['depense[description]'] = 'Facture mensuelle serveurs';
        $this->client->submit($form);

        $this->assertResponseRedirects('/console/depenses');

        $this->em()->clear();
        $depense = $this->em()->getRepository(Depense::class)->findOneBy(['reference' => self::DEPENSE_REF]);
        $this->assertNotNull($depense, 'La dépense doit être persistée.');
        $this->assertEqualsWithDelta(250.0, $depense->getMontantFloat(), 0.001);
        $this->assertSame(Depense::STATUT_PAYEE, $depense->getStatut());
        $this->assertSame(self::CHARGE_CODE, $depense->getCharge()->getCode());

        // Édition : on change le montant.
        $crawler = $this->client->request('GET', '/console/depenses/' . $depense->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form')->form();
        $form['depense[montant]'] = '275.50';
        $this->client->submit($form);
        $this->assertResponseRedirects('/console/depenses');

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Depense::class)->findOneBy(['reference' => self::DEPENSE_REF]);
        $this->assertEqualsWithDelta(275.5, $reloaded->getMontantFloat(), 0.001);

        // Suppression (CSRF).
        $id = $reloaded->getId();
        $this->client->request('GET', '/console/depenses');
        $token = $this->client->getCrawler()
            ->filter('form[action$="/console/depenses/' . $id . '"] input[name="_token"]')
            ->attr('value');

        $this->client->request('POST', '/console/depenses/' . $id, ['_token' => $token]);
        $this->assertResponseRedirects('/console/depenses');

        $this->em()->clear();
        $this->assertNull(
            $this->em()->getRepository(Depense::class)->findOneBy(['reference' => self::DEPENSE_REF]),
            'La dépense doit être supprimée.',
        );
    }

    public function testDepensesListExposesKpisAndFilters(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/depenses');
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Décaissé', $html, 'La liste des dépenses doit exposer le KPI « Décaissé ».');
        $this->assertStringContainsString('Engagé non payé', $html, 'La liste des dépenses doit exposer le KPI « Engagé non payé ».');
    }

    public function testDashboardExposesFinanceAndSaasKpisAndDepensesBlock(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console');
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('Produit (HT)', $html, 'Le tableau de bord doit afficher le KPI Produit.');
        $this->assertStringContainsString('Trésorerie', $html, 'Le tableau de bord doit afficher le KPI Trésorerie.');
        $this->assertStringContainsString('Résultat', $html, 'Le tableau de bord doit afficher le KPI Résultat.');
        $this->assertStringContainsString('Marge brute', $html, 'Le tableau de bord doit afficher le KPI Marge brute.');
        $this->assertStringContainsString('Taux de rétention', $html, 'Le tableau de bord doit afficher le KPI Taux de rétention.');
        $this->assertStringContainsString('Dernières dépenses', $html, 'Le tableau de bord doit afficher le bloc Dernières dépenses.');
    }

    public function testDepensesFragmentIsSuccessful(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/dashboard/depenses-fragment');
        $this->assertResponseIsSuccessful();
    }
}
