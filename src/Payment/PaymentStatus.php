<?php

namespace App\Payment;

/**
 * @file Statuts normalisés d'un paiement, indépendants du prestataire (PSP).
 * @description Vocabulaire commun renvoyé par toute implémentation de
 * PaymentGatewayInterface et stocké tel quel sur TokenPurchase (les constantes
 * de statut de l'entité pointent sur ces valeurs — source unique, zéro
 * divergence). Le futur adaptateur PSP réel mappe ses propres statuts vers ces
 * quatre valeurs.
 */
final class PaymentStatus
{
    /** Intention créée, en attente de confirmation (redirection / webhook). */
    public const PENDING = 'pending';

    /** Paiement encaissé : on peut créditer les tokens et facturer. */
    public const PAID = 'paid';

    /** Paiement refusé ou annulé : aucun crédit. */
    public const FAILED = 'failed';

    /** Paiement remboursé après encaissement : tokens à débiter. */
    public const REFUNDED = 'refunded';

    private function __construct()
    {
    }
}
