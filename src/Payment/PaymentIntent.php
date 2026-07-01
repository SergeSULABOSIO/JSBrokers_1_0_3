<?php

namespace App\Payment;

/**
 * @file Intention de paiement renvoyée par le PSP à la création.
 * @description `providerReference` est l'identifiant chez le prestataire (clé
 * d'idempotence côté JS Brokers, stockée sur TokenPurchase). Si `redirectUrl`
 * est renseignée, l'acheteur doit être redirigé vers la page hébergée du PSP
 * (flux asynchrone) ; sinon la confirmation est synchrone (cas du simulateur).
 */
final class PaymentIntent
{
    public function __construct(
        public readonly string $providerReference,
        public readonly string $status = PaymentStatus::PENDING,
        public readonly ?string $redirectUrl = null,
    ) {
    }

    /** Le flux exige-t-il une redirection vers le PSP (paiement asynchrone) ? */
    public function requiresRedirect(): bool
    {
        return $this->redirectUrl !== null && $this->redirectUrl !== '';
    }
}
