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

    /**
     * Le pied de page public est réutilisé sur la page « Fonctionnement des
     * tokens » : ses liens de section doivent rester navigables, c.-à-d. pointer
     * vers le portail (`/?lang=…#section`) et non vers une ancre locale morte.
     */
    public function testReusedPublicFooterLinksNavigateBackToPortal(): void
    {
        $crawler = $this->client->request('GET', '/fonctionnement-tokens?lang=fr');
        $this->assertResponseIsSuccessful();

        // Le pied de page est bien présent.
        $this->assertGreaterThan(0, $crawler->filter('footer.public-footer')->count(), 'Le pied de page public doit être affiché.');

        // Les liens de section ciblent le portail avec la langue courante + l'ancre.
        $hrefs = $crawler->filter('.public-footer__links a')->each(fn ($a) => $a->attr('href'));
        $this->assertContains('/?lang=fr#features', $hrefs);
        $this->assertContains('/?lang=fr#guide', $hrefs);
        $this->assertContains('/?lang=fr#pricing_plan', $hrefs);
        $this->assertContains('/?lang=fr#contact', $hrefs);

        // En anglais, la langue est conservée dans les liens.
        $crawler = $this->client->request('GET', '/fonctionnement-tokens?lang=en');
        $hrefs = $crawler->filter('.public-footer__links a')->each(fn ($a) => $a->attr('href'));
        $this->assertContains('/?lang=en#pricing_plan', $hrefs);
    }

    /**
     * Cohérence linguistique : le pied de page réutilisé (et les autres
     * composants traduits via « | trans ») doit suivre la langue choisie dans
     * la barre de titre — pas la locale par défaut de la requête.
     */
    public function testReusedFooterTranslationsFollowSelectedLanguage(): void
    {
        $crawler = $this->client->request('GET', '/fonctionnement-tokens?lang=fr');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Tarifs', $crawler->filter('.public-footer__links')->text());

        $crawler = $this->client->request('GET', '/fonctionnement-tokens?lang=en');
        $this->assertResponseIsSuccessful();
        $footerEn = $crawler->filter('.public-footer__links')->text();
        $this->assertStringContainsString('Pricing', $footerEn);
        $this->assertStringNotContainsString('Tarifs', $footerEn);
    }

    /**
     * Bascule de langue du pied de page : on reste sur la page courante (ici
     * « Fonctionnement des tokens ») en ne changeant que la langue — on ne
     * renvoie pas l'utilisateur vers la vitrine.
     */
    public function testFooterLanguageToggleStaysOnCurrentPage(): void
    {
        $crawler = $this->client->request('GET', '/fonctionnement-tokens?lang=fr');
        $this->assertResponseIsSuccessful();

        $langHrefs = $crawler->filter('.public-lang a')->each(fn ($a) => $a->attr('href'));
        $this->assertContains('/fonctionnement-tokens?lang=fr', $langHrefs);
        $this->assertContains('/fonctionnement-tokens?lang=en', $langHrefs);
        foreach ($langHrefs as $href) {
            $this->assertStringStartsWith('/fonctionnement-tokens', $href, 'La bascule de langue doit rester sur la page courante.');
        }
    }

    /**
     * Parcours réel (clic) : depuis la page « Fonctionnement des tokens » en
     * français, cliquer sur « EN » dans le pied de page laisse l'utilisateur sur
     * la même page, traduite en anglais. Garantit l'absence de régression
     * (pas de retour à la vitrine, et la langue est bien appliquée).
     */
    public function testClickingFooterLanguageSwitchKeepsUserOnPageInNewLanguage(): void
    {
        $crawler = $this->client->request('GET', '/fonctionnement-tokens?lang=fr');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Fonctionnement des tokens', $crawler->filter('h1')->text());

        // Clic sur le « EN » du pied de page (et non celui de la barre de titre).
        $enLink = $crawler->filter('.public-footer .public-lang a')->reduce(
            fn ($a) => trim($a->text()) === 'EN'
        )->first();
        $crawler = $this->client->click($enLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('/fonctionnement-tokens', $this->client->getRequest()->getUri());
        // La page est bien la même, désormais en anglais.
        $this->assertStringContainsString('How tokens work', $crawler->filter('h1')->text());
        $this->assertStringContainsString('Pricing', $crawler->filter('.public-footer__links')->text());
    }

    /**
     * Régression vitrine : la bascule de langue du pied de page de l'accueil
     * reste bien sur l'accueil.
     */
    public function testFooterLanguageToggleStaysOnHome(): void
    {
        $crawler = $this->client->request('GET', '/?lang=fr');
        $this->assertResponseIsSuccessful();

        $langHrefs = $crawler->filter('.public-footer .public-lang a')->each(fn ($a) => $a->attr('href'));
        foreach ($langHrefs as $href) {
            $this->assertStringStartsWith('/?lang=', $href, "La bascule de langue de l'accueil doit rester sur l'accueil.");
        }
    }

    /**
     * Sur la vitrine elle-même, les ancres restent « nues » (#section) pour
     * conserver le défilement interne sans rechargement de page.
     */
    public function testPublicFooterKeepsBareAnchorsOnPortalHome(): void
    {
        $crawler = $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();

        $hrefs = $crawler->filter('.public-footer__links a')->each(fn ($a) => $a->attr('href'));
        $this->assertContains('#pricing_plan', $hrefs);
        $this->assertContains('#features', $hrefs);
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
