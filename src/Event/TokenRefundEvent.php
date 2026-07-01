<?php

namespace App\Event;

use App\Entity\TokenPurchase;

/**
 * Émis après le remboursement d'un achat de paquet de tokens. Déclenche
 * l'e-mail d'avoir corporate (cf. MailingSubscriber).
 */
class TokenRefundEvent
{
    public function __construct(
        public readonly TokenPurchase $purchase
    ) {}
}
