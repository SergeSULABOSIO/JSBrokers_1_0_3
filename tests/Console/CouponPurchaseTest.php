<?php

namespace App\Tests\Console;

use App\Entity\Coupon;
use App\Entity\TokenPurchase;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Coupons de réduction : application au moment de l'achat de tokens (remise
 * effective, traçabilité, incrément d'usage) et rejet d'un code invalide.
 */
class CouponPurchaseTest extends WebTestCase
{
    private const EMAIL = 'phpunit-coupon@test.local';
    private const PASSWORD = 'Test1234!';
    private const CODE = 'PHPUNIT50';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();

        $user = new Utilisateur();
        $user->setEmail(self::EMAIL);
        $user->setNom('PHPUnit Coupon');
        $user->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $em->persist($user);

        // Coupon -50% valable, applicable à tous les paquets.
        $coupon = new Coupon();
        $coupon->setCode(self::CODE);
        $coupon->setType(Coupon::TYPE_PERCENT);
        $coupon->setValeur(50);
        $coupon->setDateDebut(new \DateTimeImmutable('-1 day'));
        $coupon->setDateFin(new \DateTimeImmutable('+1 day'));
        $coupon->setActif(true);
        $em->persist($coupon);

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
        $conn->executeStatement('DELETE FROM coupon WHERE code = :c', ['c' => self::CODE]);
    }

    private function user(): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL]);
    }

    private function buyForm(): \Symfony\Component\DomCrawler\Form
    {
        $crawler = $this->client->request('GET', '/admin/tokens/buy');
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form')->form();
        $form['token_purchase[pack]'] = 'intermediaire';
        $form['token_purchase[cardHolder]'] = 'John Doe';
        $form['token_purchase[cardNumber]'] = '4242 4242 4242 4242';
        $form['token_purchase[expiry]'] = '12/30';
        $form['token_purchase[cvc]'] = '123';

        return $form;
    }

    public function testValidCouponHalvesPriceAndIsRecorded(): void
    {
        $this->client->loginUser($this->user());

        $form = $this->buyForm();
        $form['token_purchase[couponCode]'] = self::CODE;
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/tokens');

        $this->em()->clear();
        $purchase = $this->em()->getRepository(TokenPurchase::class)->findOneBy(['utilisateur' => $this->user()]);
        $this->assertNotNull($purchase);
        // Paquet « intermediaire » = 10 $ → -50% = 5 $.
        $this->assertEqualsWithDelta(5.0, $purchase->getMontantUsd(), 0.001);
        $this->assertEqualsWithDelta(5.0, $purchase->getRemiseUsd(), 0.001);
        $this->assertSame(self::CODE, $purchase->getCouponCode());

        // Usage du coupon incrémenté.
        $coupon = $this->em()->getRepository(Coupon::class)->findOneBy(['code' => self::CODE]);
        $this->assertSame(1, $coupon->getUsageCount());
    }

    public function testInvalidCouponBlocksPurchaseWithError(): void
    {
        $this->client->loginUser($this->user());

        $form = $this->buyForm();
        $form['token_purchase[couponCode]'] = 'DOESNOTEXIST';
        $this->client->submit($form);

        // Pas de redirection : on reste sur la page d'achat avec un message d'erreur.
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $purchase = $this->em()->getRepository(TokenPurchase::class)->findOneBy(['utilisateur' => $this->user()]);
        $this->assertNull($purchase, 'Aucun achat ne doit être enregistré avec un coupon invalide.');
    }

    public function testPurchaseWithoutCouponStillWorks(): void
    {
        $this->client->loginUser($this->user());

        $form = $this->buyForm();
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/tokens');

        $this->em()->clear();
        $purchase = $this->em()->getRepository(TokenPurchase::class)->findOneBy(['utilisateur' => $this->user()]);
        $this->assertNotNull($purchase);
        $this->assertEqualsWithDelta(10.0, $purchase->getMontantUsd(), 0.001);
        $this->assertEqualsWithDelta(0.0, $purchase->getRemiseUsd(), 0.001);
        $this->assertNull($purchase->getCouponCode());
    }
}
