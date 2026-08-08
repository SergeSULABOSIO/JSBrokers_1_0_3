<?php

namespace App\Tests\Ai;

use App\Ai\AiEngineFailure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Message de repli du moteur IA : honnête sur un 429 (quota du fournisseur —
 * l'exception testée est une VRAIE ClientException produite par le http-client,
 * comme celles que lèvent les moteurs Claude/Gemini), générique sinon.
 */
class AiEngineFailureTest extends TestCase
{
    /** Reproduit l'exception exacte des moteurs : $response->toArray() sur un 429. */
    private function exception429(): \Throwable
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 429]));
        try {
            $client->request('POST', 'https://exemple.test/v1/messages')->toArray();
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('Le MockHttpClient aurait dû lever une exception 429.');
    }

    public function testQuotaEpuiseDonneUnMessageHonnete(): void
    {
        $message = AiEngineFailure::messagePour($this->exception429());

        $this->assertStringContainsString('saturé', $message);
        $this->assertStringContainsString('minute', $message);
        $this->assertStringContainsString('conservé', $message);
        $this->assertStringNotContainsString('problème technique', $message);
    }

    /**
     * 429 de Gemini AVEC son corps : le message doit annoncer le délai réel
     * (RetryInfo) au lieu du « patientez une petite minute » deviné, et le
     * journal doit nommer le quota violé.
     */
    public function testQuotaAvecCorpsAnnonceLeDelaiReel(): void
    {
        $corps = json_encode(['error' => [
            'code'    => 429,
            'message' => 'You exceeded your current quota. Please retry in 47.102868258s.',
            'status'  => 'RESOURCE_EXHAUSTED',
            'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [[
                    'quotaId'         => 'GenerateContentInputTokensPerModelPerMinute-FreeTier',
                    'quotaValue'      => '250000',
                    'quotaDimensions' => ['model' => 'gemini-3.5-flash-lite'],
                ]]],
                ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '47s'],
            ],
        ]]);

        $client = new MockHttpClient(new MockResponse($corps, [
            'http_code' => 429,
            'response_headers' => ['content-type' => 'application/json'],
        ]));
        try {
            $client->request('POST', 'https://exemple.test/v1/messages')->toArray();
            $this->fail('Le MockHttpClient aurait dû lever une exception 429.');
        } catch (\Throwable $e) {
        }

        $this->assertSame(47, AiEngineFailure::secondesAvantNouvelEssai($e));
        $this->assertStringContainsString('Réessayez dans 47 secondes', AiEngineFailure::messagePour($e));

        $journal = AiEngineFailure::detailsPourJournal($e);
        $this->assertSame('GenerateContentInputTokensPerModelPerMinute-FreeTier', $journal['quotaId']);
        $this->assertSame('250000', $journal['quotaPlafond']);
        $this->assertSame(47, $journal['retryApres']);
    }

    /** Un 429 sans corps exploitable reste honnête, sans inventer de délai. */
    public function testQuotaSansCorpsNAnnoncePasDeDelai(): void
    {
        $e = $this->exception429();

        $this->assertNull(AiEngineFailure::secondesAvantNouvelEssai($e));
        $this->assertStringContainsString('Patientez une petite minute', AiEngineFailure::messagePour($e));
    }

    public function testAutreEchecResteGenerique(): void
    {
        $message = AiEngineFailure::messagePour(new \RuntimeException('boom'));

        $this->assertStringContainsString('problème technique', $message);
        $this->assertStringContainsString('conservé', $message);
        $this->assertStringNotContainsString('saturé', $message);
    }
}
