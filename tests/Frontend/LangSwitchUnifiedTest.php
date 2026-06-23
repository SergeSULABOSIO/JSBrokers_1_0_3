<?php

namespace App\Tests\Frontend;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Invariant d'unification de la bascule de langue (FR/EN).
 *
 * Toutes les bascules de l'application utilisent désormais UN SEUL composant
 * (templates/components/_lang_switch.html.twig → classe .cs-lang, modèle de la
 * barre de titre Console). Ce test verrouille l'unification :
 *  - le composant unifié .cs-lang est présent (en-tête + pied de page) ;
 *  - aucune classe legacy (.public-lang / .cgu-lang / .ent-lang / .tkp-lang /
 *    .tkb-lang) ne subsiste dans le HTML rendu ;
 *  - chaque bascule expose un drapeau SVG et le libellé « FR »/« EN » visible.
 */
class LangSwitchUnifiedTest extends WebTestCase
{
    /** Pages publiques portant une (ou deux) bascule(s) de langue. */
    public function publicPages(): array
    {
        return [
            'vitrine'             => ['/?lang=fr'],
            'conditions (CGU)'    => ['/conditions-utilisation?lang=fr'],
            'fonctionnement tokens' => ['/fonctionnement-tokens?lang=fr'],
        ];
    }

    /**
     * @dataProvider publicPages
     */
    public function testPageUsesUnifiedSwitcherAndNoLegacyClass(string $url): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', $url);
        $this->assertResponseIsSuccessful();

        // Composant unifié présent (au moins l'en-tête ; souvent en-tête + pied de page).
        $switchers = $crawler->filter('.cs-lang');
        $this->assertGreaterThanOrEqual(1, $switchers->count(),
            "Le composant de langue unifié .cs-lang doit être présent sur $url.");

        // Chaque bascule : 2 options, chacune avec drapeau SVG + libellé FR/EN visible.
        foreach ($switchers as $node) {
            $sub   = new \Symfony\Component\DomCrawler\Crawler($node);
            $links = $sub->filter('a');
            $this->assertSame(2, $links->count(), "Chaque bascule de $url doit offrir 2 options.");
            $this->assertSame(2, $sub->filter('a svg')->count(), "Chaque option de $url doit afficher un drapeau SVG.");
            $this->assertSame(['FR', 'EN'], $links->each(fn ($a) => trim($a->text())),
                "Les libellés FR/EN doivent être visibles sur $url.");
        }

        // Aucune classe legacy de bascule ne doit subsister (unification complète).
        // NB : on filtre par token CSS — .public-lang ne matche PAS .public-lang__flag,
        // la classe inerte conservée sur les <svg> des drapeaux.
        foreach (['public-lang', 'cgu-lang', 'ent-lang', 'tkp-lang', 'tkb-lang'] as $legacy) {
            $this->assertSame(0, $crawler->filter('.' . $legacy)->count(),
                "La classe legacy .$legacy ne doit plus apparaître sur $url (composant unifié .cs-lang).");
        }
    }
}
