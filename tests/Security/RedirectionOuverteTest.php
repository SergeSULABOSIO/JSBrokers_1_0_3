<?php

namespace App\Tests\Security;

use App\Entity\Utilisateur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * AUCUN LIEN DE JOSEARA NE RENVOIE HORS DE JOSEARA (chantier 21, accepté par Serge le 2026-10-25).
 *
 * L'ancienne bascule de langue `/translate/{locale}/{currentURL}` faisait `redirect($currentURL)`
 * sans contrôle : un lien piégé `/translate/fr/https://site-externe…`, cliqué par un utilisateur
 * CONNECTÉ, écrivait sa langue en base puis l'envoyait ailleurs — une page d'hameçonnage qui
 * imite la connexion, avec l'adresse de joseara.com en tête du lien.
 *
 * Elle n'était plus reliée à rien (les pages basculent par `?lang=`) : supprimée. Ce test tient
 * la conséquence, pas le moyen : aucune réponse n'envoie vers un autre hôte.
 */
class RedirectionOuverteTest extends WebTestCase
{
    use SemisDeDeuxCabinetsTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function liensPieges(): iterable
    {
        yield 'adresse absolue' => ['/translate/fr/https://site-externe.example/connexion'];
        yield 'adresse sans schéma' => ['/translate/en///site-externe.example/connexion'];
        yield 'adresse encodée' => ['/translate/fr/' . rawurlencode('https://site-externe.example/')];
    }

    /** @dataProvider liensPieges */
    public function testUnLienPiegeNEnvoieJamaisHorsDuSite(string $lien): void
    {
        $seed = $this->semerLesDeuxCabinets();
        $utilisateur = $this->em()->getRepository(Utilisateur::class)->find($seed['a']['owner']);
        $localeAvant = $utilisateur->getLocale();
        $this->client->loginUser($utilisateur);

        $this->client->request('GET', $lien);
        $reponse = $this->client->getResponse();

        $destination = (string) $reponse->headers->get('Location', '');
        $hote = parse_url($destination, PHP_URL_HOST);
        self::assertTrue(
            $destination === '' || $hote === null || $hote === 'localhost',
            sprintf('« %s » renvoie vers « %s ».', $lien, $destination),
        );
        // Et la langue de l'utilisateur n'a pas été écrite par un lien qu'il n'a pas choisi.
        $this->em()->clear();
        self::assertSame(
            $localeAvant,
            $this->em()->getRepository(Utilisateur::class)->find($seed['a']['owner'])->getLocale(),
            'La langue n’est pas écrite par ce lien.',
        );
    }
}
