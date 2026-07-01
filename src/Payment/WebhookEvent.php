<?php

namespace App\Payment;

/**
 * @file Événement webhook normalisé après vérification de signature.
 * @description Renvoyé par PaymentGatewayInterface::parseWebhook(). Porte la
 * référence prestataire (pour retrouver le TokenPurchase) et le statut
 * normalisé à appliquer de façon idempotente. `payload` conserve le corps brut
 * à des fins de traçabilité.
 */
final class WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $providerReference,
        public readonly string $status,
        public readonly ?string $failureReason = null,
        public readonly array $payload = [],
    ) {
    }
}
