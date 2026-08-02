<?php

namespace App\Tests\Nouveautes;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Page publique « Nouveautés de la plateforme » (route app_changelog) :
 *  - elle s'affiche sans authentification, dans les deux langues ;
 *  - chaque mise à jour porte sa date complète, sa référence, son titre et sa
 *    description — c'est tout l'intérêt de la page pour l'utilisateur ;
 *  - le nom/version devient un lien vers cette page partout où il s'affiche.
 */
class NouveautesPageTest extends WebTestCase
{
    public function testPageAccessibleAnonymementDansLesDeuxLangues(): void
    {
        $client = static::createClient();

        $client->request('GET', '/nouveautes?lang=fr');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/nouveautes?lang=en');
        $this->assertResponseIsSuccessful();
    }

    public function testChaqueEntreePorteDateReferenceTitreEtDescription(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/nouveautes?lang=fr');
        $this->assertResponseIsSuccessful();

        $entrees = $crawler->filter('.chg-item');

        if (!self::gitEstDisponible()) {
            // Sans git, la page doit expliquer la situation plutôt que planter.
            $this->assertSame(0, $entrees->count());
            $this->assertGreaterThan(0, $crawler->filter('.chg-empty')->count(), 'État dégradé attendu sans git.');

            return;
        }

        // Le dépôt est lisible : la page DOIT lister des mises à jour. Sans cette
        // exigence, une régression d'accès à git (cf. l'environnement passé au
        // processus fils) passerait inaperçue derrière l'état dégradé.
        $this->assertGreaterThan(0, $entrees->count(), 'Le dépôt est lisible : le journal ne peut pas être vide.');

        $premiere = $entrees->first();
        $this->assertSame(1, $premiere->filter('time.chg-date')->count(), 'Date complète présente.');
        $this->assertNotSame('', trim($premiere->filter('time.chg-date')->text()));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{7,40}$/',
            trim($premiere->filter('code.chg-ref')->text()),
            'Le code de référence du commit est affiché.',
        );
        $this->assertNotSame('', trim($premiere->filter('.chg-title')->text()), 'Titre du commit présent.');
        $this->assertNotSame('', trim($premiere->filter('.chg-desc')->text()), 'Description de l’amélioration présente.');

        // La date reste lisible par une machine autant que par un humain.
        $this->assertNotSame('', $premiere->filter('time.chg-date')->attr('datetime'));
    }

    public function testEntreesAntechronologiques(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/nouveautes?lang=fr');

        $dates = $crawler->filter('.chg-item time.chg-date')->each(
            fn ($n) => new \DateTimeImmutable($n->attr('datetime'))
        );

        if (count($dates) < 2) {
            $this->markTestSkipped('Historique trop court pour vérifier l’ordre.');
        }

        $triees = $dates;
        usort($triees, fn ($a, $b) => $b <=> $a);
        $this->assertEquals($triees, $dates, 'La mise à jour la plus récente vient en premier.');
    }

    public function testPiedDePagePublicRenvoieVersLesNouveautes(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/conditions-utilisation?lang=fr');
        $this->assertResponseIsSuccessful();

        $hrefs = $crawler->filter('.public-footer__credit a')->each(fn ($a) => $a->attr('href'));
        $this->assertContains('/nouveautes', $hrefs, 'Le pied de page public expose le lien des nouveautés.');
    }

    public function testEcranDeConnexionRenvoieVersLesNouveautes(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');
        $this->assertResponseIsSuccessful();

        $lien = $crawler->filter('a.auth__brand-version');
        $this->assertSame(1, $lien->count(), 'Le panneau de marque affiche la version cliquable.');
        $this->assertSame('/nouveautes', $lien->attr('href'));
    }

    /**
     * Disponibilité de git, constatée SANS passer par le service : c'est ce qui
     * permet d'exiger un journal non vide plutôt que d'accepter l'état dégradé.
     */
    private static function gitEstDisponible(): bool
    {
        return is_dir(\dirname(__DIR__, 2) . '/.git')
            && (new ExecutableFinder())->find('git') !== null;
    }
}
