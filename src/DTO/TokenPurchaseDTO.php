<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données saisies lors d'un achat de paquet de tokens (paiement SIMULÉ).
 * Le numéro de carte n'est jamais persisté : seuls ses 4 derniers chiffres
 * sont conservés sur le TokenPurchase.
 */
class TokenPurchaseDTO
{
    /**
     * Identifiant du paquet choisi. La validité est garantie par le ChoiceType
     * du formulaire (choix issus du plan tarifaire courant) puis re-vérifiée
     * côté contrôleur — d'où l'absence de contrainte Choice statique ici, qui
     * empêcherait l'achat d'un paquet ajouté dynamiquement via la Console.
     */
    #[Assert\NotBlank]
    public string $pack = 'intermediaire';

    /** Code de réduction optionnel (validé par CouponService au moment de l'achat). */
    public ?string $couponCode = null;

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

    /** 4 derniers chiffres du numéro de carte (sans espaces). */
    public function cardLast4(): string
    {
        $digits = preg_replace('/\D/', '', $this->cardNumber);

        return substr($digits, -4);
    }
}
