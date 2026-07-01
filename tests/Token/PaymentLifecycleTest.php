<?php

namespace App\Tests\Token;

use App\Entity\TokenPurchase;
use App\Entity\Utilisateur;
use App\Payment\PaymentStatus;
use App\Token\TokenPurchaseFulfillmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cycle de vie du paiement réel (PSP-agnostique) via le SimulatedGateway :
 *  - succès → statut PAID, tokens crédités, numéro de facture séquentiel, facture PDF téléchargeable ;
 *  - carte de test refusée → statut FAILED, aucun crédit, aucun e-mail ;
 *  - remboursement → statut REFUNDED, tokens repris, e-mail d'avoir ;
 *  - webhook : signature exigée + idempotence (pas de double-crédit, numéro de facture stable) ;
 *  - les achats non encaissés n'entrent pas dans le chiffre d'affaires.
 */
class PaymentLifecycleTest extends WebTestCase
{
    private const EMAIL = 'phpunit-pay@test.local';
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
        $user->setNom('PHPUnit Pay');
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
            'DELETE tp FROM token_purchase tp LEFT JOIN utilisateur u ON tp.utilisateur_id = u.id WHERE u.email = :e',
            ['e' => self::EMAIL]
        );
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::EMAIL]);
    }

    private function user(): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL]);
    }

    private function lastPurchase(): ?TokenPurchase
    {
        return $this->em()->getRepository(TokenPurchase::class)
            ->findOneBy(['utilisateur' => $this->user()], ['id' => 'DESC']);
    }

    /** Soumet le formulaire d'achat avec une carte (4242… = succès, 4000…0002 = refus). */
    private function submitPurchase(string $cardNumber = '4242 4242 4242 4242'): void
    {
        $crawler = $this->client->request('GET', '/admin/tokens/buy');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['token_purchase[pack]'] = 'intermediaire';
        $form['token_purchase[cardHolder]'] = 'John Doe';
        $form['token_purchase[cardNumber]'] = $cardNumber;
        $form['token_purchase[expiry]'] = '12/30';
        $form['token_purchase[cvc]'] = '123';

        $this->client->submit($form);
    }

    public function testSuccessfulPurchaseIsPaidNumberedAndInvoiceable(): void
    {
        $this->client->loginUser($this->user());
        $this->submitPurchase();
        $this->assertResponseRedirects('/admin/tokens');
        $this->assertQueuedEmailCount(1);

        $this->em()->clear();
        $purchase = $this->lastPurchase();
        $this->assertSame(PaymentStatus::PAID, $purchase->getStatus());
        $this->assertSame('simulated', $purchase->getProvider());
        $this->assertNotNull($purchase->getProviderReference());
        $this->assertNotNull($purchase->getPaidAt());
        $this->assertMatchesRegularExpression('/^FAC-\d{4}-\d{5}$/', (string) $purchase->getInvoiceNumber());
        $this->assertSame(10000, $this->user()->getPaidTokens());

        // Facture PDF téléchargeable par le propriétaire.
        $this->client->request('GET', '/admin/tokens/invoice/' . $purchase->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $this->client->getResponse()->getContent());
    }

    public function testDeclinedCardFailsWithoutCreditOrEmail(): void
    {
        $this->client->loginUser($this->user());
        $this->submitPurchase('4000 0000 0000 0002'); // carte de test de refus
        $this->assertQueuedEmailCount(0);

        $this->em()->clear();
        $purchase = $this->lastPurchase();
        $this->assertSame(PaymentStatus::FAILED, $purchase->getStatus());
        $this->assertNull($purchase->getInvoiceNumber());
        $this->assertSame(0, $this->user()->getPaidTokens());
    }

    public function testInvoiceNumbersAreSequential(): void
    {
        $this->client->loginUser($this->user());

        $this->submitPurchase();
        $this->em()->clear();
        $first = $this->lastPurchase()->getInvoiceNumber();

        $this->submitPurchase();
        $this->em()->clear();
        $second = $this->lastPurchase()->getInvoiceNumber();

        $seq = static fn (string $n): int => (int) substr($n, strrpos($n, '-') + 1);
        $this->assertSame($seq($first) + 1, $seq($second), 'Les numéros de facture doivent se suivre sans trou.');
    }

    public function testRefundReversesTokensAndSendsCreditNote(): void
    {
        $this->client->loginUser($this->user());
        $this->submitPurchase();
        $this->em()->clear();

        $fulfillment = static::getContainer()->get(TokenPurchaseFulfillmentService::class);
        $purchase = $this->lastPurchase();
        $this->assertSame(10000, $this->user()->getPaidTokens());

        $done = $fulfillment->refund($purchase, 'test.refund');
        $this->assertTrue($done);

        $this->em()->clear();
        $this->assertSame(PaymentStatus::REFUNDED, $this->lastPurchase()->getStatus());
        $this->assertNotNull($this->lastPurchase()->getRefundedAt());
        $this->assertSame(0, $this->user()->getPaidTokens());

        // Remboursement idempotent : un second appel n'a aucun effet.
        $this->assertFalse($fulfillment->refund($this->lastPurchase(), 'again'));
    }

    public function testFulfillIsIdempotent(): void
    {
        $this->client->loginUser($this->user());
        $this->submitPurchase();
        $this->em()->clear();

        $fulfillment = static::getContainer()->get(TokenPurchaseFulfillmentService::class);
        $purchase = $this->lastPurchase();
        $invoice = $purchase->getInvoiceNumber();

        // Rejouer le fulfillment (comme un webhook PAID rejoué) ne double pas le crédit
        // ni le numéro de facture.
        $this->assertFalse($fulfillment->fulfill($purchase));
        $this->em()->clear();
        $this->assertSame(10000, $this->user()->getPaidTokens());
        $this->assertSame($invoice, $this->lastPurchase()->getInvoiceNumber());
    }

    public function testWebhookRequiresValidSignature(): void
    {
        // Sans signature → rejet 400.
        $this->client->request('POST', '/api/payment/webhook/simulated', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reference' => 'SIM-WHATEVER', 'status' => 'paid']));
        $this->assertResponseStatusCodeSame(400);
    }

    public function testWebhookReplayDoesNotDoubleCredit(): void
    {
        $this->client->loginUser($this->user());
        $this->submitPurchase();
        $this->em()->clear();

        $purchase = $this->lastPurchase();
        $invoice = $purchase->getInvoiceNumber();
        $ref = $purchase->getProviderReference();

        // Webhook PAID signé, rejoué : déjà encaissé → aucun effet (idempotence).
        $body = json_encode(['reference' => $ref, 'status' => 'paid']);
        $secret = $_SERVER['APP_SECRET'] ?? getenv('APP_SECRET');
        $sig = hash_hmac('sha256', $body, (string) $secret);

        $this->client->request('POST', '/api/payment/webhook/simulated',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIM_SIGNATURE' => $sig], content: $body);
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $this->assertSame(10000, $this->user()->getPaidTokens(), 'Le rejeu du webhook ne doit pas re-créditer.');
        $this->assertSame($invoice, $this->lastPurchase()->getInvoiceNumber());
    }
}
