<?php

namespace App\Payment\Gateway;

use App\Payment\PaymentContext;
use App\Payment\PaymentIntent;
use App\Payment\PaymentResult;
use App\Payment\PaymentStatus;
use App\Payment\WebhookEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * @file Implémentation PSP simulée — repli par défaut et support des tests.
 * @description Reproduit le comportement historique (« toute carte bien formée
 * réussit ») derrière l'abstraction PaymentGatewayInterface, SANS aucun appel
 * externe ni saisie de carte côté gateway. Confirmation SYNCHRONE (pas de
 * redirection). L'issue est pilotable via la métadonnée `outcome` du contexte
 * (« paid » par défaut, « failed » pour tester un refus) et encodée dans la
 * référence afin que confirm() reste sans état.
 *
 * Reste l'implémentation active tant qu'aucun PSP réel n'est branché : il
 * suffira d'ajouter un adaptateur et de rebasculer l'alias de service.
 */
class SimulatedGateway implements PaymentGatewayInterface
{
    private const PREFIX_PAID = 'SIM';
    private const PREFIX_FAILED = 'SIMFAIL';

    public function __construct(private string $paymentWebhookSecret)
    {
    }

    public function name(): string
    {
        return 'simulated';
    }

    public function createIntent(PaymentContext $context): PaymentIntent
    {
        $failed = ($context->metadata['outcome'] ?? 'paid') === 'failed';
        $prefix = $failed ? self::PREFIX_FAILED : self::PREFIX_PAID;

        // Référence locale auto-suffisante : confirm() en déduit l'issue sans état.
        $reference = sprintf('%s-%s', $prefix, strtoupper(bin2hex(random_bytes(8))));

        // Pas de redirectUrl → confirmation synchrone dans la foulée de l'achat.
        return new PaymentIntent($reference, PaymentStatus::PENDING);
    }

    public function confirm(string $providerReference): PaymentResult
    {
        if (str_starts_with($providerReference, self::PREFIX_FAILED . '-')) {
            return new PaymentResult($providerReference, PaymentStatus::FAILED, 'payment.simulated_declined');
        }

        return new PaymentResult($providerReference, PaymentStatus::PAID);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $body = $request->getContent();
        $signature = (string) $request->headers->get('X-Sim-Signature', '');
        $expected = hash_hmac('sha256', $body, $this->paymentWebhookSecret);

        // Comparaison à temps constant : rejette toute signature absente/invalide.
        if ($signature === '' || !hash_equals($expected, $signature)) {
            throw new \RuntimeException('Signature de webhook invalide.');
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['reference']) || empty($data['status'])) {
            throw new \RuntimeException('Charge utile de webhook invalide.');
        }

        $status = match ((string) $data['status']) {
            PaymentStatus::PAID, 'succeeded'           => PaymentStatus::PAID,
            PaymentStatus::FAILED, 'failed', 'declined' => PaymentStatus::FAILED,
            PaymentStatus::REFUNDED, 'refunded'         => PaymentStatus::REFUNDED,
            default                                     => PaymentStatus::PENDING,
        };

        return new WebhookEvent(
            (string) $data['reference'],
            $status,
            isset($data['failureReason']) ? (string) $data['failureReason'] : null,
            $data,
        );
    }
}
