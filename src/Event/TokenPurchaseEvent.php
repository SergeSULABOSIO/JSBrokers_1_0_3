<?php

namespace App\Event;

use App\Entity\TokenPurchase;

/**
 * Émis après un achat de paquet de tokens réussi. Déclenche l'e-mail de
 * confirmation corporate (cf. MailingSubscriber).
 */
class TokenPurchaseEvent
{
    public function __construct(
        public readonly TokenPurchase $purchase
    ) {}
}
