<?php

namespace App\Tests\Ai;

use App\Ai\Debit\BudgetDebit;
use App\Ai\Fournisseur\OrdreDesFournisseurs;
use App\Ai\Oreille\FournisseurDOreille;
use App\Ai\Oreille\OreilleDeKet;
use App\Ai\Oreille\Transcription;
use App\Ai\Oreille\TranscriptionElevenLabs;
use App\Ai\Oreille\TranscriptionGemini;
use App\Ai\Voix\MemoireDEpuisement;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * LES OREILLES DE KET : ElevenLabs puis Gemini, et le navigateur en dernier recours
 * (503 rendu par la route). Une oreille ne fait qu'ENTENDRE : le texte repart ensuite
 * par le circuit ordinaire d'un message, le moteur restant seul à penser.
 */
class OreilleDeKetTest extends TestCase
{
    /** Un WAV minuscule mais valide : 44 octets d'en-tête et un échantillon. */
    private const WAV = "RIFF....WAVEfmt \x10\x00\x00\x00\x01\x00\x01\x00\x80\x3e\x00\x00\x00\x7d\x00\x00\x02\x00\x10\x00data\x02\x00\x00\x00\x01\x00";

    /**
     * Le corps d'une requête, quelle que soit la forme que lui donne le client HTTP :
     * une chaîne (JSON), ou une fonction qui rend les morceaux d'un envoi multipart.
     */
    private static function corps(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }
        $texte = '';
        if (is_callable($body)) {
            while (($morceau = $body(4096)) !== '') {
                $texte .= $morceau;
            }

            return $texte;
        }
        foreach ($body as $morceau) {
            $texte .= $morceau;
        }

        return $texte;
    }

    private function elevenLabs(MockHttpClient $http, string $cle = 'xi-test', ?ArrayAdapter $cache = null): TranscriptionElevenLabs
    {
        return new TranscriptionElevenLabs($http, new MemoireDEpuisement($cache ?? new ArrayAdapter()), new NullLogger(), $cle, 'scribe_v2');
    }

    private function gemini(MockHttpClient $http, string $cle = 'gm-test'): TranscriptionGemini
    {
        return new TranscriptionGemini($http, new BudgetDebit(new ArrayAdapter()), new MemoireDEpuisement(new ArrayAdapter()), new NullLogger(), $cle, 'modele-oreille');
    }

    public function testElevenLabsEnvoieLeWavEtRendLeTexte(): void
    {
        $vu = [];
        $http = new MockHttpClient(function (string $methode, string $url, array $options) use (&$vu): MockResponse {
            $vu = ['methode' => $methode, 'url' => $url, 'entetes' => $options['headers'], 'corps' => self::corps($options['body'])];

            return new MockResponse(json_encode(['text' => 'Quel est le taux de la Caution ?']));
        });

        $transcription = $this->elevenLabs($http)->transcrire(self::WAV, 'fr');

        self::assertSame(Transcription::COMPLET, $transcription->statut);
        self::assertSame('Quel est le taux de la Caution ?', $transcription->texte);
        self::assertSame('elevenlabs', $transcription->fournisseur);
        self::assertSame('POST', $vu['methode']);
        self::assertSame('https://api.elevenlabs.io/v1/speech-to-text', $vu['url']);
        self::assertContains('xi-api-key: xi-test', $vu['entetes']);
        self::assertStringContainsString('scribe_v2', $vu['corps']);
        self::assertStringContainsString('fra', $vu['corps'], 'la langue part au format ISO-639-3');
        self::assertStringContainsString('parole.wav', $vu['corps']);
    }

    public function testElevenLabsQuotaEpuiseEstMemoriseEtPartageAvecLaVoix(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"detail":{"code":"quota_exceeded"}}',
            ['http_code' => 401],
        ));
        $cache = new ArrayAdapter();

        self::assertSame(Transcription::QUOTA, $this->elevenLabs($http, 'xi-test', $cache)->transcrire(self::WAV, 'fr')->statut);
        // La mémoire d'épuisement est la même que celle de la voix : la clé « elevenlabs ».
        self::assertTrue((new MemoireDEpuisement($cache))->estEpuise('elevenlabs'));
        self::assertSame(Transcription::QUOTA, $this->elevenLabs($http, 'xi-test', $cache)->transcrire(self::WAV, 'fr')->statut);
        self::assertSame(1, $http->getRequestsCount(), 'plus aucun appel une fois le mois épuisé');
    }

    public function testGeminiEnvoieLAudioEtNeGardeQueLesMots(): void
    {
        $vu = '';
        $http = new MockHttpClient(function (string $methode, string $url, array $options) use (&$vu): MockResponse {
            $vu = self::corps($options['body']);

            return new MockResponse(json_encode([
                'candidates' => [['content' => ['parts' => [['text' => "Transcription : \"Liste mes clients.\""]]]]],
            ]));
        });

        $transcription = $this->gemini($http)->transcrire(self::WAV, 'fr');

        self::assertSame('Liste mes clients.', $transcription->texte, 'le préfixe et les guillemets du modèle sont retirés');
        self::assertStringContainsString('inlineData', $vu);
        self::assertStringContainsString('audio/wav', json_decode($vu, true)['contents'][0]['parts'][1]['inlineData']['mimeType']);
        self::assertStringContainsString('Ne réponds pas', json_decode($vu, true)['contents'][0]['parts'][0]['text'], 'la consigne interdit de répondre');
    }

    public function testGeminiSatureRendQuota(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 429]));

        self::assertSame(Transcription::QUOTA, $this->gemini($http)->transcrire(self::WAV, 'fr')->statut);
    }

    public function testSansCleAucunAppel(): void
    {
        $http = new MockHttpClient([]);

        self::assertFalse($this->elevenLabs($http, '')->estDisponible());
        self::assertSame(Transcription::INDISPONIBLE, $this->elevenLabs($http, '')->transcrire(self::WAV, 'fr')->statut);
        self::assertSame(Transcription::INDISPONIBLE, $this->gemini($http, '')->transcrire(self::WAV, 'fr')->statut);
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testLOrdreEstRespecteEtLaMainPasseALOreilleSuivante(): void
    {
        $eleven = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"detail":{"code":"quota_exceeded"}}', ['http_code' => 401]));
        $gemini = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'Entendu par Gemini.']]]]],
        ])));

        $oreilles = new OreilleDeKet([$this->gemini($gemini), $this->elevenLabs($eleven)], 'elevenlabs,gemini');
        $transcription = $oreilles->transcrire(self::WAV, 'fr');

        self::assertSame('Entendu par Gemini.', $transcription->texte);
        self::assertSame('gemini', $transcription->fournisseur);
        self::assertSame(1, $eleven->getRequestsCount(), 'ElevenLabs a bien été essayé en premier');
    }

    public function testToutesLesOreillesEpuiseesRendQuota(): void
    {
        $refus = new MockHttpClient(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 429]));
        $oreilles = new OreilleDeKet([$this->elevenLabs($refus), $this->gemini($refus)], 'elevenlabs,gemini');

        self::assertSame(Transcription::QUOTA, $oreilles->transcrire(self::WAV, 'fr')->statut);
    }

    public function testAucuneOreilleDisponibleRendIndisponible(): void
    {
        $oreilles = new OreilleDeKet([$this->elevenLabs(new MockHttpClient([]), '')], 'elevenlabs,gemini');

        self::assertFalse($oreilles->estDisponible());
        self::assertSame(Transcription::INDISPONIBLE, $oreilles->transcrire(self::WAV, 'fr')->statut);
    }

    /** Le silence n'est pas un échec : on n'essaie pas l'oreille suivante pour rien. */
    public function testUnSilenceArreteLaChaineSansFacturer(): void
    {
        $eleven = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode(['text' => '   '])));
        $gemini = new MockHttpClient([]);

        $transcription = (new OreilleDeKet([$this->elevenLabs($eleven), $this->gemini($gemini)], 'elevenlabs,gemini'))
            ->transcrire(self::WAV, 'fr');

        self::assertSame(Transcription::COMPLET, $transcription->statut);
        self::assertSame('', $transcription->texte);
        self::assertFalse($transcription->aDuTexte());
        self::assertSame(0, $gemini->getRequestsCount());
    }

    /** L'ordre est écrit une seule fois, pour la bouche comme pour les oreilles. */
    public function testLOrdreDesFournisseursEstPartageAvecLaVoix(): void
    {
        $a = $this->elevenLabs(new MockHttpClient([]));
        $b = $this->gemini(new MockHttpClient([]));

        self::assertSame([$b, $a], OrdreDesFournisseurs::ordonner([$a, $b], 'gemini,elevenlabs'));
        self::assertSame([], OrdreDesFournisseurs::ordonner([$a, $b], 'inconnu'));
        self::assertSame([$b], OrdreDesFournisseurs::disponibles([$this->elevenLabs(new MockHttpClient([]), ''), $b]));
    }

    public function testLesConstantesDeStatutSontCommunesAuxDeuxContrats(): void
    {
        self::assertSame(Transcription::QUOTA, \App\Ai\Voix\FournisseurDeVoix::QUOTA);
        self::assertSame(16000, FournisseurDOreille::TAUX_ECHANTILLONNAGE);
    }
}
