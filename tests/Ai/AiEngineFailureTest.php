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

    /** Lève l'exception réelle du http-client pour une réponse donnée. */
    private function exceptionDe(string $corps, int $code, array $entetes = []): \Throwable
    {
        $client = new MockHttpClient(new MockResponse($corps, [
            'http_code'        => $code,
            'response_headers' => $entetes + ['content-type' => 'application/json'],
        ]));
        try {
            $client->request('POST', 'https://exemple.test/v1/messages')->toArray();
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail(sprintf('Le MockHttpClient aurait dû lever une exception %d.', $code));
    }

    /**
     * 429 d'Anthropic : le délai vient de l'en-tête « retry-after », là où Gemini
     * le met dans le corps. Les deux chemins doivent marcher — c'est la seule
     * partie de cette classe qui était déjà agnostique du fournisseur.
     */
    public function testLeDelaiAnthropicSeLitDansLEnTete(): void
    {
        $e = $this->exceptionDe(
            json_encode(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'Rate limit.']]),
            429,
            ['retry-after' => '12', 'anthropic-ratelimit-input-tokens-remaining' => '0'],
        );

        $this->assertSame(12, AiEngineFailure::secondesAvantNouvelEssai($e));
        $this->assertStringContainsString('Réessayez dans 12 secondes', AiEngineFailure::messagePour($e));

        $journal = AiEngineFailure::detailsPourJournal($e);
        $this->assertSame('rate_limit_error', $journal['statut'], 'Anthropic dit « type » là où Google dit « status ».');
        $this->assertSame('0', $journal['quotaEntreeRestante'],
            'Le solde publié en en-tête doit atterrir dans le journal : sans lui, la saturation reste un mystère.');
    }

    /**
     * LE PIÈGE DU PLAFOND DE DÉPENSE. Même code HTTP que la saturation, et
     * pourtant l'inverse : aucune attente ne le libère. Lui servir « réessayez
     * dans N secondes » enverrait l'utilisateur relancer en boucle jusqu'au
     * premier du mois.
     */
    public function testLePlafondDeDepenseNEstPasUneSaturationPassagere(): void
    {
        $e = $this->exceptionDe(json_encode(['type' => 'error', 'error' => [
            'type'    => 'rate_limit_error',
            'message' => 'You have reached your API usage limits: your organization has crossed its '
                . 'monthly API usage threshold. You will regain access on 2026-10-01 at 00:00 UTC.',
            'details' => ['error_code' => 'enforced_spend_limit_reached'],
        ]]), 429);

        $this->assertTrue(AiEngineFailure::estPlafondDeDepense($e));
        $this->assertNull(AiEngineFailure::secondesAvantNouvelEssai($e),
            'Aucun délai n’est annoncé, et il ne faut surtout pas en inventer un.');
        $this->assertSame('2026-10-01', AiEngineFailure::dateDeReouverture($e));

        $message = AiEngineFailure::messagePour($e);
        $this->assertStringContainsString('plafond de dépense mensuel', $message);
        $this->assertStringContainsString('2026-10-01', $message);
        $this->assertStringNotContainsString('Réessayez dans', $message);
        $this->assertStringNotContainsString('Patientez', $message);

        $this->assertSame('enforced_spend_limit_reached', AiEngineFailure::detailsPourJournal($e)['quotaId']);
    }

    /** Une saturation ordinaire ne doit PAS être prise pour un plafond de dépense. */
    public function testUneSaturationOrdinaireNEstPasUnPlafondDeDepense(): void
    {
        $this->assertFalse(AiEngineFailure::estPlafondDeDepense($this->exception429()));
    }

    /**
     * 529 « overloaded_error » d'Anthropic : la même situation que le 503 de
     * Google — leur charge, pas notre quota — donc le même traitement.
     */
    public function testLaSurchargeAnthropicEstReconnueCommeIndisponibilite(): void
    {
        $e = $this->exceptionDe(
            json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]),
            529,
        );

        $this->assertTrue(AiEngineFailure::estMoteurIndisponible($e));
        $this->assertFalse(AiEngineFailure::estLimiteDeDebit($e));
    }

    public function testAutreEchecResteGenerique(): void
    {
        $message = AiEngineFailure::messagePour(new \RuntimeException('boom'));

        $this->assertStringContainsString('problème technique', $message);
        $this->assertStringContainsString('conservé', $message);
        $this->assertStringNotContainsString('saturé', $message);
    }
}
