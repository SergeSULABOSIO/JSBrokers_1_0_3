<?php

namespace App\Tests\Frontend;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Hero de la vitrine publique — message StoryBrand (Donald Miller).
 *
 * Verrouille la scène narrative du bandeau principal :
 *  - le héros est le courtier, adossé à ses assurés (titre H1) ;
 *  - le problème est nommé (administratif éparpillé qui le ralentit) ;
 *  - JS Brokers apparaît comme le guide (empathie + autorité : 10 ans
 *    de courtage) et promet le succès (redevenir le héros de ses assurés) ;
 *  - les deux appels à l'action (plan) restent sous le message ;
 *  - le tout est servi dans les deux langues (FR/EN), sans clé fuitée.
 */
class HomeHeroStorybrandTest extends WebTestCase
{
    public function languages(): array
    {
        return [
            'français' => [
                'fr',
                'Vos assurés comptent sur vous. Pas sur la paperasse.',
                ['bordereaux en retard', 'JS Brokers', '10 ans de courtage', 'un seul espace de travail', 'leur héros'],
                'Créer un compte',
                'Voir les tarifs',
            ],
            'anglais' => [
                'en',
                'Your clients count on you. Not on your paperwork.',
                ['late statements', 'JS Brokers', '10 years of brokerage', 'one workspace', 'their hero'],
                'Create an account',
                'View pricing',
            ],
        ];
    }

    /**
     * @dataProvider languages
     */
    public function testHeroTellsTheStorybrandScene(
        string $lang,
        string $headline,
        array $leadFragments,
        string $primaryCta,
        string $ghostCta
    ): void {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/?lang=' . $lang);
        $this->assertResponseIsSuccessful();

        $hero = $crawler->filter('.public-hero');
        $this->assertSame(1, $hero->count(), 'La vitrine doit conserver un unique bandeau hero.');

        // Le héros de l'histoire (le courtier et ses assurés) porte le titre.
        $this->assertSame($headline, trim($hero->filter('h1')->text()),
            "Le titre du hero doit porter la scène StoryBrand en $lang.");

        // Le sous-titre déroule problème → guide → plan → succès.
        $lead = $hero->filter('.public-hero__lead');
        $this->assertSame(1, $lead->count(), 'Le hero doit conserver son paragraphe d\'accroche.');
        $leadText = $lead->text();
        foreach ($leadFragments as $fragment) {
            $this->assertStringContainsString($fragment, $leadText,
                "L'accroche doit contenir « $fragment » (élément StoryBrand) en $lang.");
        }

        // L'appel à l'action (le « plan ») reste juste sous le message :
        // CTA primaire (compte/espace) + CTA secondaire (tarifs).
        $actions = $hero->filter('.public-hero__actions');
        $this->assertSame(1, $actions->count(), 'Le hero doit conserver sa rangée d\'actions.');
        $this->assertStringContainsString($primaryCta, $actions->filter('.btn-hero-primary')->text(),
            "Le CTA primaire doit rester présent en $lang.");
        $this->assertStringContainsString($ghostCta, $actions->filter('.btn-hero-ghost')->text(),
            "Le CTA tarifs doit rester présent en $lang.");

        // Aucune clé de traduction fuitée dans le bandeau rendu.
        $this->assertStringNotContainsString('security_Work_faster', $hero->text(),
            'Aucune clé de traduction ne doit fuiter dans le hero rendu.');
    }
}
