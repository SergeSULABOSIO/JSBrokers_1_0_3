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

    public function testAutreEchecResteGenerique(): void
    {
        $message = AiEngineFailure::messagePour(new \RuntimeException('boom'));

        $this->assertStringContainsString('problème technique', $message);
        $this->assertStringContainsString('conservé', $message);
        $this->assertStringNotContainsString('saturé', $message);
    }
}
