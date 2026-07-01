<?php

namespace App\Payment;

/**
 * @file Contexte d'un encaissement transmis au PSP (données NON sensibles).
 * @description Aucune donnée de carte n'y figure : avec un PSP réel, la saisie
 * carte se fait sur la page hébergée du prestataire. Le champ `metadata` permet
 * de transporter des indications neutres (ex. issue de test pour le simulateur)
 * sans coupler le contrôleur à un PSP particulier.
 */
final class PaymentContext
{
    /**
     * @param float                $montant   Montant TTC à encaisser.
     * @param string               $devise    Code devise ISO (ex. « USD »).
     * @param string               $reference Référence d'achat lisible (TokenPurchase::reference).
     * @param string               $libelle   Libellé affiché à l'acheteur / au PSP.
     * @param string|null          $email     E-mail de l'acheteur (reçu PSP).
     * @param string|null          $returnUrl URL de retour après paiement (PSP réel).
     * @param array<string, mixed> $metadata  Données neutres complémentaires.
     */
    public function __construct(
        public readonly float $montant,
        public readonly string $devise,
        public readonly string $reference,
        public readonly string $libelle,
        public readonly ?string $email = null,
        public readonly ?string $returnUrl = null,
        public readonly array $metadata = [],
    ) {
    }
}
