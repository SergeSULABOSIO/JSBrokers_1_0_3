<?php

namespace App\Payment;

/**
 * @file Résultat d'une confirmation de paiement (synchrone ou au retour PSP).
 * @description Statut normalisé (cf. PaymentStatus) + raison d'échec éventuelle
 * à journaliser sur TokenPurchase::failedReason.
 */
final class PaymentResult
{
    public function __construct(
        public readonly string $providerReference,
        public readonly string $status,
        public readonly ?string $failureReason = null,
    ) {
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::PAID;
    }
}
