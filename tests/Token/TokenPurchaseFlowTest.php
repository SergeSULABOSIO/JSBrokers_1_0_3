<?php

namespace App\Tests\Token;

use App\Entity\TokenPurchase;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
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

        $langHrefs = $crawler->filter('.public-footer .cs-lang a')->each(fn ($a) => $a->attr('href'));
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
        $enLink = $crawler->filter('.public-footer .cs-lang a')->reduce(
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

        $langHrefs = $crawler->filter('.public-footer .cs-lang a')->each(fn ($a) => $a->attr('href'));
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

    /**
     * Modernisation : la barre de titre de l'espace compte affiche le sélecteur
     * de langue à drapeaux, et le pied de page officiel JS Brokers est présent.
     */
    public function testAccountPageShowsOfficialFooterAndFlagSwitch(): void
    {
        $this->client->loginUser($this->user());
        $crawler = $this->client->request('GET', '/admin/tokens');
        $this->assertResponseIsSuccessful();

        // Pied de page officiel.
        $this->assertGreaterThan(0, $crawler->filter('footer.public-footer')->count(),
            'Le pied de page officiel JS Brokers doit être présent sur la page compte.');

        // Bascule de langue à drapeaux dans la barre de titre (composant unifié .cs-lang).
        $this->assertSame(2, $crawler->filter('.tkp-actions .cs-lang a svg')->count(),
            'Chaque option de langue doit afficher un drapeau SVG.');
        $codes = $crawler->filter('.tkp-actions .cs-lang a')->each(fn ($n) => trim($n->text()));
        $this->assertSame(['FR', 'EN'], $codes);

        // Les liens de langue restent sur la page compte.
        foreach ($crawler->filter('.tkp-actions .cs-lang a')->each(fn ($a) => $a->attr('href')) as $href) {
            $this->assertStringStartsWith('/admin/tokens', $href, 'La bascule doit rester sur la page compte.');
        }
    }

    /**
     * Bascule de langue de l'espace authentifié : ?lang= traduit le rendu ET
     * persiste la préférence de l'utilisateur (le rendu suivant, sans ?lang=,
     * reste dans la langue choisie).
     */
    public function testAccountLanguageSwitchPersistsUserLocale(): void
    {
        $this->client->loginUser($this->user());

        // Par défaut : français.
        $crawler = $this->client->request('GET', '/admin/tokens');
        $this->assertStringContainsString('Mes tokens', $crawler->filter('h1')->text());

        // Bascule EN via ?lang= → page traduite.
        $crawler = $this->client->request('GET', '/admin/tokens?lang=en');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('My tokens', $crawler->filter('h1')->text());

        // Préférence persistée en base.
        $this->em()->clear();
        $this->assertSame('en', $this->user()->getLocale(), 'La langue choisie doit être persistée sur l\'utilisateur.');

        // Persistance effective : sans ?lang=, la page reste en anglais.
        $crawler = $this->client->request('GET', '/admin/tokens');
        $this->assertStringContainsString('My tokens', $crawler->filter('h1')->text());
    }

    /**
     * Idem sur la page de paiement : drapeaux dans la barre de titre, liens
     * restant sur la page d'achat, et persistance de la langue.
     */
    public function testBuyPageLanguageSwitchPersistsUserLocale(): void
    {
        $this->client->loginUser($this->user());

        $crawler = $this->client->request('GET', '/admin/tokens/buy?lang=en');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Top up my tokens', $crawler->filter('h1')->text());

        // Drapeaux + liens restant sur la page d'achat.
        $this->assertSame(2, $crawler->filter('.tkb-actions .cs-lang a svg')->count());
        foreach ($crawler->filter('.tkb-actions .cs-lang a')->each(fn ($a) => $a->attr('href')) as $href) {
            $this->assertStringStartsWith('/admin/tokens/buy', $href, 'La bascule doit rester sur la page d\'achat.');
        }

        // Préférence persistée.
        $this->em()->clear();
        $this->assertSame('en', $this->user()->getLocale());
    }

    /**
     * Feedback visuel de la bascule de langue : sur l'espace compte ET la page
     * d'achat, chaque lien de langue (barre de titre + pied de page) déclenche la
     * barre de progression du haut (top-progress#show), et l'infrastructure
     * (contrôleur + cible de barre) est bien présente. Pas de régression sur le
     * comportement de navigation (les href restent sur la page courante).
     */
    public function testLanguageSwitchTriggersProgressBarOnTokenPages(): void
    {
        $this->client->loginUser($this->user());

        foreach (['/admin/tokens' => '.tkp-actions .cs-lang a', '/admin/tokens/buy' => '.tkb-actions .cs-lang a'] as $url => $titleLangSelector) {
            $crawler = $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful();

            // Infrastructure de barre de progression présente.
            $this->assertGreaterThan(0, $crawler->filter('[data-controller~="top-progress"]')->count(),
                "Le contrôleur top-progress doit envelopper $url.");
            $this->assertGreaterThan(0, $crawler->filter('[data-top-progress-target="bar"]')->count(),
                "La barre de progression doit être présente sur $url.");

            // Bascule de la barre de titre + bascule du pied de page : data-action.
            foreach ([$titleLangSelector, '.public-footer .cs-lang a'] as $selector) {
                $links = $crawler->filter($selector);
                $this->assertSame(2, $links->count(), "Deux options de langue attendues ($selector sur $url).");
                foreach ($links->each(fn ($a) => $a->attr('data-action')) as $action) {
                    $this->assertNotNull($action, "Le lien de langue ($selector sur $url) doit porter un data-action.");
                    $this->assertStringContainsString('top-progress#show', $action,
                        "Le clic sur la langue ($selector sur $url) doit déclencher la barre de progression.");
                }
            }
        }
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
        $this->submitValidPurchase();

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

    /**
     * Le pied de page officiel JS Brokers est aussi présent sur la page de
     * paiement, et sa bascule de langue reste sur cette page.
     */
    public function testBuyPageShowsOfficialFooter(): void
    {
        $this->client->loginUser($this->user());
        $crawler = $this->client->request('GET', '/admin/tokens/buy');
        $this->assertResponseIsSuccessful();

        $this->assertGreaterThan(0, $crawler->filter('footer.public-footer')->count(),
            'Le pied de page officiel doit être présent sur la page de paiement.');

        foreach ($crawler->filter('.public-footer .cs-lang a')->each(fn ($a) => $a->attr('href')) as $href) {
            $this->assertStringStartsWith('/admin/tokens/buy', $href, 'La bascule du pied de page doit rester sur la page d\'achat.');
        }
    }

    /**
     * L'e-mail de confirmation suit la langue de l'utilisateur : un utilisateur
     * en anglais reçoit un objet anglais, et la locale est figée dans le contexte
     * du template (rendu correct même en envoi différé/asynchrone).
     */
    public function testConfirmationEmailFollowsUserLanguageEnglish(): void
    {
        $user = $this->user();
        $user->setLocale('en');
        $this->em()->flush();

        $this->client->loginUser($this->user());
        $this->submitValidPurchase();

        $this->assertQueuedEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertInstanceOf(TemplatedEmail::class, $email);

        // Objet traduit en anglais.
        $this->assertStringContainsString('Payment confirmation', $email->getSubject());
        $this->assertStringNotContainsString('Confirmation de paiement', $email->getSubject());

        // Corps rendu dans la langue de l'utilisateur (rendu figé à l'envoi),
        // y compris le chrome partagé du layout (signature).
        $body = $this->renderedHtml($email);
        $this->assertStringContainsString('Payment confirmed', $body);
        $this->assertStringNotContainsString('Paiement confirmé', $body);
        $this->assertStringContainsString('The JS Brokers team', $body);
    }

    /**
     * Non-régression : par défaut (utilisateur français), l'e-mail reste en
     * français.
     */
    public function testConfirmationEmailDefaultsToFrench(): void
    {
        $this->client->loginUser($this->user());
        $this->submitValidPurchase();

        $this->assertQueuedEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertInstanceOf(TemplatedEmail::class, $email);

        $this->assertStringContainsString('Confirmation de paiement', $email->getSubject());

        $body = $this->renderedHtml($email);
        $this->assertStringContainsString('Paiement confirmé', $body);
        $this->assertStringNotContainsString('Payment confirmed', $body);
        // (l'apostrophe est échappée à l'affichage → on teste un fragment sûr)
        $this->assertStringContainsString('équipe JS Brokers', $body);
    }

    /** Corps HTML rendu de l'e-mail (l'envoi asynchrone le rend au dispatch). */
    private function renderedHtml(TemplatedEmail $email): string
    {
        $body = $email->getHtmlBody();
        if (is_resource($body)) {
            $body = stream_get_contents($body);
        }

        return (string) $body;
    }

    /** Soumet le formulaire d'achat avec une fausse carte valide (paquet « intermediaire »). */
    private function submitValidPurchase(): void
    {
        $crawler = $this->client->request('GET', '/admin/tokens/buy');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['token_purchase[pack]'] = 'intermediaire';
        $form['token_purchase[cardHolder]'] = 'John Doe';
        $form['token_purchase[cardNumber]'] = '4242 4242 4242 4242';
        $form['token_purchase[expiry]'] = '12/30';
        $form['token_purchase[cvc]'] = '123';

        $this->client->submit($form);
    }
}
