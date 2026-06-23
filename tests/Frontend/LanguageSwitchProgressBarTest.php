<?php

namespace App\Tests\Frontend;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Feedback visuel de la bascule de langue (FR/EN).
 *
 * Partout où le bloc de boutons de langue apparaît (en-tête ET pied de page),
 * cliquer pour changer de langue déclenche une navigation serveur complète :
 * on doit donc afficher la barre de progression du haut de page
 * (top-progress#show) pour donner un retour immédiat à l'utilisateur
 * (Nielsen #1 — visibilité de l'état du système).
 *
 * Ces tests garantissent, sur chaque page publique concernée, que :
 *  - le contrôleur Stimulus `top-progress` enveloppe bien les boutons ;
 *  - la cible de barre (`data-top-progress-target="bar"`) est présente ;
 *  - chaque lien de bascule de langue porte `click->top-progress#show`.
 */
class LanguageSwitchProgressBarTest extends WebTestCase
{
    /** Le contrôleur et sa cible de barre doivent être présents sur la page. */
    private function assertProgressBarWiring(Crawler $crawler, string $context): void
    {
        $this->assertGreaterThan(
            0,
            $crawler->filter('[data-controller~="top-progress"]')->count(),
            "Le contrôleur top-progress doit envelopper $context."
        );
        $this->assertGreaterThan(
            0,
            $crawler->filter('[data-top-progress-target="bar"]')->count(),
            "La barre de progression (cible) doit être présente sur $context."
        );
    }

    /** Chaque lien du sélecteur ciblé doit déclencher la barre au clic. */
    private function assertLangLinksTriggerProgress(Crawler $crawler, string $selector, string $context): void
    {
        $links = $crawler->filter($selector);
        $this->assertSame(2, $links->count(), "Deux options de langue attendues dans $context.");

        foreach ($links->each(fn ($a) => $a->attr('data-action')) as $action) {
            $this->assertNotNull($action, "Le lien de langue de $context doit porter un data-action.");
            $this->assertStringContainsString(
                'top-progress#show',
                $action,
                "Le clic sur la langue dans $context doit déclencher la barre de progression."
            );
        }
    }

    public function testHomeTopbarAndFooterLanguageSwitchTriggerProgressBar(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/?lang=fr');
        $this->assertResponseIsSuccessful();

        $this->assertProgressBarWiring($crawler, "la vitrine");
        $this->assertLangLinksTriggerProgress($crawler, '.public-topbar .cs-lang a', "l'en-tête de la vitrine");
        $this->assertLangLinksTriggerProgress($crawler, '.public-footer .cs-lang a', "le pied de page de la vitrine");
    }

    public function testTermsPageLanguageSwitchTriggersProgressBar(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/conditions-utilisation?lang=fr');
        $this->assertResponseIsSuccessful();

        $this->assertProgressBarWiring($crawler, "la page des conditions d'utilisation");
        // Barre de titre (.cs-lang) ET pied de page public réutilisé (.cs-lang).
        $this->assertLangLinksTriggerProgress($crawler, '.cgu-topbar .cs-lang a', "la barre de titre des CGU");
        $this->assertLangLinksTriggerProgress($crawler, '.public-footer .cs-lang a', "le pied de page des CGU");
    }

    public function testTokensInfoPageLanguageSwitchTriggersProgressBar(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fonctionnement-tokens?lang=fr');
        $this->assertResponseIsSuccessful();

        $this->assertProgressBarWiring($crawler, "la page de fonctionnement des tokens");
        $this->assertLangLinksTriggerProgress($crawler, '.cgu-topbar .cs-lang a', "la barre de titre des tokens");
        $this->assertLangLinksTriggerProgress($crawler, '.public-footer .cs-lang a', "le pied de page des tokens");
    }

    /**
     * Le clic réel sur « EN » fonctionne toujours (non-régression) : la page se
     * recharge dans la nouvelle langue. Le data-action n'empêche pas la
     * navigation (la barre reste affichée jusqu'au chargement suivant).
     */
    public function testClickingLanguageStillNavigatesOnTermsPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/conditions-utilisation?lang=fr');
        $this->assertResponseIsSuccessful();

        $enLink = $crawler->filter('.cgu-topbar .cs-lang a')->reduce(
            fn ($a) => trim($a->text()) === 'EN'
        )->first();
        $crawler = $client->click($enLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('/conditions-utilisation', $client->getRequest()->getUri());
    }
}
