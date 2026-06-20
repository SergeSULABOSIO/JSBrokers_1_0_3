<?php

namespace App\Tests\Token;

use App\Entity\TokenPurchase;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Parcours bout-en-bout de l'achat (simulé) de tokens :
 *  - la page compte et la page d'achat s'affichent ;
 *  - la page publique d'explication est accessible ;
 *  - un paiement par fausse carte crédite le solde prépayé, journalise l'achat
 *    et déclenche l'e-mail de confirmation.
 */
class TokenPurchaseFlowTest extends WebTestCase
{
    private const EMAIL = 'phpunit-token@test.local';
    private const PASSWORD = 'Test1234!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        $user = new Utilisateur();
        $user->setEmail(self::EMAIL);
        $user->setNom('PHPUnit Token');
        $user->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $em->persist($user);
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
            "DELETE tp FROM token_purchase tp LEFT JOIN utilisateur u ON tp.utilisateur_id = u.id WHERE u.email = :e",
            ['e' => self::EMAIL]
        );
        $conn->executeStatement("DELETE FROM utilisateur WHERE email = :e", ['e' => self::EMAIL]);
    }

    private function user(): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL]);
    }

    public function testPublicTokensInfoPageIsAccessible(): void
    {
        $this->client->request('GET', '/fonctionnement-tokens');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/fonctionnement-tokens?lang=en');
        $this->assertResponseIsSuccessful();
    }

    public function testAccountAndBuyPagesRender(): void
    {
        $this->client->loginUser($this->user());

        $this->client->request('GET', '/admin/tokens');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/tokens/buy');
        $this->assertResponseIsSuccessful();
    }

    public function testBalanceJsonReturnsFreeAllowance(): void
    {
        $this->client->loginUser($this->user());
        $this->client->request('GET', '/admin/tokens/balance');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(1000, $data['free']);
        $this->assertSame(0, $data['paid']);
    }

    public function testBuyCreditsTokensAndSendsConfirmationEmail(): void
    {
        $this->client->loginUser($this->user());

        $crawler = $this->client->request('GET', '/admin/tokens/buy');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['token_purchase[pack]'] = 'intermediaire';
        $form['token_purchase[cardHolder]'] = 'John Doe';
        $form['token_purchase[cardNumber]'] = '4242 4242 4242 4242';
        $form['token_purchase[expiry]'] = '12/30';
        $form['token_purchase[cvc]'] = '123';

        $this->client->submit($form);

        // PRG → redirection vers l'espace compte.
        $this->assertResponseRedirects('/admin/tokens');

        // E-mail de confirmation corporate (routé en asynchrone → mis en file).
        $this->assertQueuedEmailCount(1);

        // Solde prépayé crédité de 10 000 + achat journalisé.
        $this->em()->clear();
        $user = $this->user();
        $this->assertSame(10000, $user->getPaidTokens());

        $purchase = $this->em()->getRepository(TokenPurchase::class)->findOneBy(['utilisateur' => $user]);
        $this->assertNotNull($purchase);
        $this->assertSame(10000, $purchase->getTokens());
        $this->assertSame('4242', $purchase->getCardLast4());
    }
}
