<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class DemandeContactDTO
{

    #[Assert\NotBlank]
    #[Assert\Length(min: 2)]
    public string $name = "";

    #[Assert\NotBlank]
    #[Assert\Email()]
    public string $email = "";

    /** Objet du message choisi par le visiteur (libellé lisible, stocké tel quel). */
    #[Assert\NotBlank]
    public string $objet = "";

    #[Assert\NotBlank]
    #[Assert\Length(min: 4, max:400)]
    public string $message = "";

    /** Le visiteur accepte (volontairement) de laisser un numéro de téléphone. */
    public bool $wantsPhone = false;

    /**
     * Numéro de téléphone — facultatif. Validé uniquement si le visiteur a coché
     * la case (Assert\When) : on ne bloque jamais l'envoi quand il n'en laisse pas.
     */
    #[Assert\When(
        expression: 'this.wantsPhone === true',
        constraints: [
            new Assert\NotBlank(message: 'ContactForm.phone_required'),
            new Assert\Length(min: 6, max: 30),
            new Assert\Regex(
                pattern: '/^[0-9+().\s-]{6,}$/',
                message: 'ContactForm.phone_invalid',
            ),
        ],
    )]
    public ?string $phone = null;
}
