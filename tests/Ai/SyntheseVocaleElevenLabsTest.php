<?php

namespace App\Tests\Ai;

use App\Ai\Voix\FournisseurDeVoix;
use App\Ai\Voix\MemoireDEpuisement;
use App\Ai\Voix\SyntheseVocaleElevenLabs;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Voix de Ket par ElevenLabs : requête conforme à l'API de flux, PCM 24 kHz rendu tel
 * quel, quota du mois mémorisé, et aucun appel sans clé ou en moteur simulé.
 */
class SyntheseVocaleElevenLabsTest extends TestCase
{
    private function fournisseur(MockHttpClient $http, string $cle = 'xi-test', string $moteur = '', ?ArrayAdapter $cache = null): SyntheseVocaleElevenLabs
    {
        return new SyntheseVocaleElevenLabs(
            $http,
            new MemoireDEpuisement($cache ?? new ArrayAdapter()),
            new NullLogger(),
            $cle,
            'voix-123',
            'eleven_flash_v2_5',
            $moteur,
        );
    }

    /** @return array{0: list<string>, 1: string} */
    private static function consommer(\Generator $flux): array
    {
        $morceaux = [];
        foreach ($flux as $morceau) {
            $morceaux[] = $morceau;
        }

        return [$morceaux, $flux->getReturn()];
    }

    public function testLaRequeteRespecteLApiEtLeFluxPcmEstRenduTelQuel(): void
    {
        $vue = [];
        $http = new MockHttpClient(function (string $methode, string $url, array $options) use (&$vue): MockResponse {
            $vue = ['methode' => $methode, 'url' => $url, 'options' => $options];

            return new MockResponse(["\x01\x00\x02", "\x00\x03\x00"], ['response_headers' => ['content-type' => 'audio/pcm']]);
        });

        [$morceaux, $statut] = self::consommer($this->fournisseur($http)->flux('Le taux de la Caution est de 15 %.'));

        self::assertSame(FournisseurDeVoix::COMPLET, $statut);
        self::assertSame("\x01\x00\x02\x00\x03\x00", implode('', $morceaux), 'octets transmis sans altération');
        self::assertSame('POST', $vue['methode']);
        self::assertSame('https://api.elevenlabs.io/v1/text-to-speech/voix-123/stream?output_format=pcm_24000', $vue['url']);
        self::assertContains('xi-api-key: xi-test', $vue['options']['headers']);
        $corps = json_decode($vue['options']['body'], true);
        self::assertSame('Le taux de la Caution est de 15 %.', $corps['text']);
        self::assertSame('eleven_flash_v2_5', $corps['model_id']);
        self::assertSame('fr', $corps['language_code']);
        self::assertArrayHasKey('stability', $corps['voice_settings']);
    }

    public function testLeQuotaDuMoisEpuiseEstMemorise(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"detail":{"status":"quota_exceeded","message":"This request exceeds your quota."}}',
            ['http_code' => 401],
        ));
        $cache = new ArrayAdapter();

        [, $statut] = self::consommer($this->fournisseur($http, 'xi-test', '', $cache)->flux('Texte.'));
        self::assertSame(FournisseurDeVoix::QUOTA, $statut);

        // Écoute suivante : plus aucun appel jusqu'au mois prochain.
        [, $statut] = self::consommer($this->fournisseur($http, 'xi-test', '', $cache)->flux('Autre.'));
        self::assertSame(FournisseurDeVoix::QUOTA, $statut);
        self::assertSame(1, $http->getRequestsCount());
    }

    /** Le nouveau format d'erreur porte la cause dans `detail.code` (réponse réelle du 2026-09-17). */
    public function testLeQuotaEstReconnuAuNouveauFormatDErreur(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"detail":{"type":"payment_required","code":"quota_exceeded","message":"Quota exceeded."}}',
            ['http_code' => 401],
        ));

        [, $statut] = self::consommer($this->fournisseur($http)->flux('Texte.'));

        self::assertSame(FournisseurDeVoix::QUOTA, $statut);
    }

    /** Voix de la bibliothèque sur un compte gratuit : erreur de configuration, la voix suivante parle. */
    public function testUneVoixReserveeAuxPlansPayantsEstUnEchecSansMemorisation(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"detail":{"type":"payment_required","code":"paid_plan_required","message":"Free users cannot use library voices via the API."}}',
            ['http_code' => 402],
        ));
        $cache = new ArrayAdapter();

        [$morceaux, $statut] = self::consommer($this->fournisseur($http, 'xi-test', '', $cache)->flux('Texte.'));
        self::assertSame([], $morceaux);
        self::assertSame(FournisseurDeVoix::ECHEC, $statut);

        // Non mémorisé comme épuisé : corriger ELEVENLABS_VOIX_KET suffit, sans attendre.
        self::consommer($this->fournisseur($http, 'xi-test', '', $cache)->flux('Texte.'));
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testSansCleOuEnMoteurSimuleAucunAppel(): void
    {
        $http = new MockHttpClient([]);

        self::assertFalse($this->fournisseur($http, '')->estDisponible());
        [, $statut] = self::consommer($this->fournisseur($http, '')->flux('Texte.'));
        self::assertSame(FournisseurDeVoix::INDISPONIBLE, $statut);

        [, $statut] = self::consommer($this->fournisseur($http, 'xi-test', 'simulated')->flux('Texte.'));
        self::assertSame(FournisseurDeVoix::INDISPONIBLE, $statut);
        self::assertSame(0, $http->getRequestsCount());
    }
}
