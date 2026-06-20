<?php

namespace App\Token;

/**
 * @file Exception levée quand le solde de tokens du propriétaire ne suffit pas
 * à couvrir une opération métrée (lecture ou écriture).
 * @description Le métrage est bloquant : ni la donnée n'est servie, ni la
 * persistance n'a lieu. L'utilisateur doit recharger ou attendre le
 * renouvellement de l'allocation gratuite (porté par $nextRenewalAt).
 */
class InsufficientTokensException extends \RuntimeException
{
    public function __construct(
        public readonly int $required,
        public readonly int $available,
        public readonly ?\DateTimeImmutable $nextRenewalAt = null,
        string $message = 'Solde de tokens insuffisant.',
    ) {
        parent::__construct($message);
    }
}
