<?php

namespace App\DTO;

use App\Token\TokenPricing;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données saisies lors d'un achat de paquet de tokens (paiement SIMULÉ).
 * Le numéro de carte n'est jamais persisté : seuls ses 4 derniers chiffres
 * sont conservés sur le TokenPurchase.
 */
class TokenPurchaseDTO
{
    /** Identifiant du paquet choisi (cf. TokenPricing::PACKS). */
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [TokenPurchaseDTO::class, 'packKeys'])]
    public string $pack = 'intermediaire';

    #[Assert\NotBlank(message: 'token_buy.card_holder_required')]
    #[Assert\Length(min: 2, max: 80)]
    public string $cardHolder = '';

    /** 13 à 19 chiffres (espaces tolérés à la saisie, nettoyés au traitement). */
    #[Assert\NotBlank(message: 'token_buy.card_number_required')]
    #[Assert\Regex(pattern: '/^[0-9 ]{13,23}$/', message: 'token_buy.card_number_invalid')]
    public string $cardNumber = '';

    /** Format MM/AA. */
    #[Assert\NotBlank(message: 'token_buy.expiry_required')]
    #[Assert\Regex(pattern: '/^(0[1-9]|1[0-2])\/[0-9]{2}$/', message: 'token_buy.expiry_invalid')]
    public string $expiry = '';

    #[Assert\NotBlank(message: 'token_buy.cvc_required')]
    #[Assert\Regex(pattern: '/^[0-9]{3,4}$/', message: 'token_buy.cvc_invalid')]
    public string $cvc = '';

    /** @return string[] Clés de paquets valides (pour la contrainte Choice). */
    public static function packKeys(): array
    {
        return array_keys(TokenPricing::PACKS);
    }

    /** 4 derniers chiffres du numéro de carte (sans espaces). */
    public function cardLast4(): string
    {
        $digits = preg_replace('/\D/', '', $this->cardNumber);

        return substr($digits, -4);
    }
}
