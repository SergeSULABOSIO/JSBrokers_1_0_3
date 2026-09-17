<?php

namespace App\Tests\Ai;

use App\Ai\Debit\BudgetDebit;
use App\Ai\Voix\CacheAudio;
use App\Ai\Voix\MemoireDEpuisement;
use App\Ai\Voix\SyntheseVocaleGemini;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Voix de Ket : flux PCM depuis Gemini, chaîne de modèles sur quota épuisé (palier
 * gratuit : 10 générations par jour et par modèle), et cache WAV des réécoutes.
 */
class SyntheseVocaleGeminiTest extends TestCase
{
    private const MODELES = 'modele-a,modele-b';

    private static function evenement(string $pcm): string
    {
        return 'data: ' . json_encode([
            'candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/l16', 'data' => base64_encode($pcm)]]]]]],
        ]) . "\n\n";
    }

    private function synthese(MockHttpClient $http, string $moteur = '', ?ArrayAdapter $cache = null): SyntheseVocaleGemini
    {
        return new SyntheseVocaleGemini(
            $http,
            new BudgetDebit(new ArrayAdapter()),
            new MemoireDEpuisement($cache ?? new ArrayAdapter()),
            new NullLogger(),
            'cle-test',
            self::MODELES,
            'Aoede',
            $moteur,
        );
    }

    /** @return array{0: list<string>, 1: string} morceaux émis et statut final */
    private static function consommer(\Generator $flux): array
    {
        $morceaux = [];
        foreach ($flux as $morceau) {
            $morceaux[] = $morceau;
        }

        return [$morceaux, $flux->getReturn()];
    }

    public function testLeFluxRendLesMorceauxDansLOrdre(): void
    {
        $requetes = [];
        $http = new MockHttpClient(function (string $methode, string $url, array $options) use (&$requetes): MockResponse {
            $requetes[] = ['url' => $url, 'corps' => $options['body'] ?? ''];

            // Un événement coupé en deux morceaux réseau : le tampon doit le recoller.
            $e1 = self::evenement("\x01\x00\x02\x00");

            return new MockResponse([substr($e1, 0, 20), substr($e1, 20), self::evenement("\x03\x00")]);
        });

        [$morceaux, $statut] = self::consommer($this->synthese($http)->flux('Bonjour, je suis Ket.'));

        self::assertSame(SyntheseVocaleGemini::COMPLET, $statut);
        self::assertSame(["\x01\x00\x02\x00", "\x03\x00"], $morceaux);
        self::assertStringContainsString('modele-a:streamGenerateContent?alt=sse', $requetes[0]['url']);
        self::assertStringContainsString('Aoede', $requetes[0]['corps']);
        self::assertStringContainsString('Bonjour, je suis Ket.', $requetes[0]['corps']);
    }

    public function testUnModeleEpuisePasseLaMainAuSuivantEtResteMemorise(): void
    {
        $urls = [];
        $http = new MockHttpClient(function (string $methode, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return str_contains($url, 'modele-a')
                ? new MockResponse('{"error":{"code":429}}', ['http_code' => 429])
                : new MockResponse([self::evenement("\x05\x00")]);
        });
        $cache = new ArrayAdapter();

        [$morceaux, $statut] = self::consommer($this->synthese($http, '', $cache)->flux('Texte.'));
        self::assertSame(SyntheseVocaleGemini::COMPLET, $statut);
        self::assertSame(["\x05\x00"], $morceaux);

        // Seconde lecture : le modèle épuisé n'est plus interrogé jusqu'à la remise à zéro.
        self::consommer($this->synthese($http, '', $cache)->flux('Autre texte.'));
        self::assertCount(1, array_filter($urls, static fn (string $u): bool => str_contains($u, 'modele-a')));
    }

    public function testToutLaChaineEpuiseeRendQuotaSansAucunSon(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 429]));

        [$morceaux, $statut] = self::consommer($this->synthese($http)->flux('Texte.'));

        self::assertSame([], $morceaux);
        self::assertSame(SyntheseVocaleGemini::QUOTA, $statut);
    }

    public function testLeMoteurSimuleNAppelleRien(): void
    {
        $http = new MockHttpClient([]);

        [$morceaux, $statut] = self::consommer($this->synthese($http, 'simulated')->flux('Texte.'));

        self::assertSame(SyntheseVocaleGemini::INDISPONIBLE, $statut);
        self::assertSame([], $morceaux);
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testLeCacheRendUnWavValideEtRelisible(): void
    {
        $racine = sys_get_temp_dir() . '/ket-voix-test-' . bin2hex(random_bytes(4));
        $cache = new CacheAudio($racine);
        $pcm = str_repeat("\x10\x00", 2400); // 0,1 s à 24 kHz

        self::assertNull($cache->lire(7, 'Aoede', 'Bonjour.'));
        $cache->ecrire(7, 'Aoede', 'Bonjour.', $pcm);
        $wav = $cache->lire(7, 'Aoede', 'Bonjour.');

        self::assertNotNull($wav);
        self::assertSame(44 + \strlen($pcm), \strlen($wav));
        self::assertSame('RIFF', substr($wav, 0, 4));
        self::assertSame('WAVE', substr($wav, 8, 4));
        self::assertSame(24000, unpack('V', substr($wav, 24, 4))[1]);
        self::assertSame($pcm, substr($wav, 44));
        self::assertNull($cache->lire(7, 'Kore', 'Bonjour.'), 'une autre voix ne réutilise pas l’audio');
        self::assertNull($cache->lire(8, 'Aoede', 'Bonjour.'), 'un autre cabinet ne réutilise pas l’audio');

        array_map('unlink', glob($racine . '/7/*') ?: []);
        @rmdir($racine . '/7');
        @rmdir($racine);
    }
}
