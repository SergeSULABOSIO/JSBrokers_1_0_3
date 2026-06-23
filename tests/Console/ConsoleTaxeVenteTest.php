<?php

namespace App\Tests\Console;

use App\Entity\TaxeVente;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Console JS Brokers : CRUD de la rubrique « Fiscalité » (taxes sur les ventes).
 * Vérifie l'accès (agent vs utilisateur), la création, l'édition et la
 * suppression via les formulaires, ainsi que la persistance en base.
 */
class ConsoleTaxeVenteTest extends WebTestCase
{
    private const ADMIN = 'phpunit-taxe-admin@test.local';
    private const USER  = 'phpunit-taxe-user@test.local';
    private const PASSWORD = 'Test1234!';
    private const CODE = 'PHPUNIT-TVA';

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
        $conn->executeStatement(
            'DELETE FROM utilisateur WHERE email IN (:e)',
            ['e' => [self::ADMIN, self::USER]],
            ['e' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement('DELETE FROM taxe_vente WHERE code = :c', ['c' => self::CODE]);
    }

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function taxe(): ?TaxeVente
    {
        return $this->em()->getRepository(TaxeVente::class)->findOneBy(['code' => self::CODE]);
    }

    public function testRegularUserIsForbidden(): void
    {
        $this->client->loginUser($this->user(self::USER));
        $this->client->request('GET', '/console/taxes');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testTaxesListExposesAmountOnRevenueColumn(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/taxes');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Montant sur le CA', (string) $this->client->getResponse()->getContent(), 'La liste des taxes doit exposer la colonne « Montant sur le CA ».');
    }

    public function testVentesPageExposesPreTaxAndTaxKpis(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        $this->client->request('GET', '/console/ventes');
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Revenu HT', $html, 'La page des ventes doit afficher le revenu HT.');
        $this->assertStringContainsString('Taxes', $html, 'La page des ventes doit afficher les taxes.');
    }

    public function testAdminCreatesEditsAndDeletesTaxe(): void
    {
        $this->client->loginUser($this->user(self::ADMIN));

        // Création.
        $crawler = $this->client->request('GET', '/console/taxes/new');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['taxe_vente[code]'] = self::CODE;
        $form['taxe_vente[libelle]'] = 'Taxe sur la valeur ajoutée';
        $form['taxe_vente[taux]'] = '16';
        $form['taxe_vente[autoriteNom]'] = 'Direction Générale des Impôts';
        $form['taxe_vente[autoriteAbreviation]'] = 'DGI';
        $form['taxe_vente[actif]'] = true;
        $this->client->submit($form);

        $this->assertResponseRedirects('/console/taxes');

        $this->em()->clear();
        $taxe = $this->taxe();
        $this->assertNotNull($taxe, 'La taxe doit être persistée.');
        $this->assertSame('DGI', $taxe->getAutoriteAbreviation());
        $this->assertEqualsWithDelta(16.0, $taxe->getTauxFloat(), 0.0001);
        $this->assertTrue($taxe->isActif());

        // Édition : on modifie le taux.
        $crawler = $this->client->request('GET', '/console/taxes/' . $taxe->getId() . '/edit');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['taxe_vente[taux]'] = '20';
        $this->client->submit($form);
        $this->assertResponseRedirects('/console/taxes');

        $this->em()->clear();
        $this->assertEqualsWithDelta(20.0, $this->taxe()->getTauxFloat(), 0.0001);

        // Suppression (CSRF).
        $id = $this->taxe()->getId();
        $this->client->request('GET', '/console/taxes');
        $token = $this->client->getCrawler()
            ->filter('form[action$="/console/taxes/' . $id . '"] input[name="_token"]')
            ->attr('value');

        $this->client->request('POST', '/console/taxes/' . $id, ['_token' => $token]);
        $this->assertResponseRedirects('/console/taxes');

        $this->em()->clear();
        $this->assertNull($this->taxe(), 'La taxe doit être supprimée.');
    }
}
