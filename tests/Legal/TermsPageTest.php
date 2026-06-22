<?php

namespace App\Tests\Legal;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page publique « Conditions d'utilisation » (route app_terms) :
 *  - elle s'affiche dans les deux langues ;
 *  - elle réutilise le pied de page public JS Brokers ;
 *  - les liens de section du pied de page renvoient vers le portail ;
 *  - la bascule de langue reste sur la page courante et traduit le rendu.
 */
class TermsPageTest extends WebTestCase
{
    public function testTermsPageIsAccessibleInBothLanguages(): void
    {
        $client = static::createClient();

        $client->request('GET', '/conditions-utilisation?lang=fr');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/conditions-utilisation?lang=en');
        $this->assertResponseIsSuccessful();
    }

    public function testPublicFooterIsDisplayedOnTermsPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/conditions-utilisation?lang=fr');

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('footer.public-footer')->count(), 'Le pied de page public doit être affiché.');
    }

    public function testFooterSectionLinksNavigateBackToPortal(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/conditions-utilisation?lang=fr');
        $hrefs = $crawler->filter('.public-footer__links a')->each(fn ($a) => $a->attr('href'));
        $this->assertContains('/?lang=fr#pricing_plan', $hrefs);
        $this->assertContains('/?lang=fr#contact', $hrefs);

        $crawler = $client->request('GET', '/conditions-utilisation?lang=en');
        $hrefs = $crawler->filter('.public-footer__links a')->each(fn ($a) => $a->attr('href'));
        $this->assertContains('/?lang=en#pricing_plan', $hrefs);
    }

    public function testFooterLanguageToggleStaysOnTermsPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/conditions-utilisation?lang=fr');
        $this->assertResponseIsSuccessful();

        $langHrefs = $crawler->filter('.public-footer .public-lang a')->each(fn ($a) => $a->attr('href'));
        $this->assertContains('/conditions-utilisation?lang=fr', $langHrefs);
        $this->assertContains('/conditions-utilisation?lang=en', $langHrefs);
        foreach ($langHrefs as $href) {
            $this->assertStringStartsWith('/conditions-utilisation', $href, 'La bascule de langue doit rester sur la page courante.');
        }
    }

    public function testFooterTranslationsFollowSelectedLanguage(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/conditions-utilisation?lang=fr');
        $this->assertStringContainsString('Tarifs', $crawler->filter('.public-footer__links')->text());

        $crawler = $client->request('GET', '/conditions-utilisation?lang=en');
        $footerEn = $crawler->filter('.public-footer__links')->text();
        $this->assertStringContainsString('Pricing', $footerEn);
        $this->assertStringNotContainsString('Tarifs', $footerEn);
    }

    public function testClickingFooterLanguageSwitchKeepsUserOnPageInNewLanguage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/conditions-utilisation?lang=fr');
        $this->assertResponseIsSuccessful();

        $enLink = $crawler->filter('.public-footer .public-lang a')->reduce(
            fn ($a) => trim($a->text()) === 'EN'
        )->first();
        $crawler = $client->click($enLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('/conditions-utilisation', $client->getRequest()->getUri());
        $this->assertStringContainsString('Pricing', $crawler->filter('.public-footer__links')->text());
    }
}
