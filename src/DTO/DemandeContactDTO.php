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

    #[Assert\NotBlank]
    #[Assert\Length(min: 4, max:400)]
    public string $message = "";
}
