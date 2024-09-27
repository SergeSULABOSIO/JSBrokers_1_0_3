<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class ConnexionDTO
{
    #[Assert\NotBlank]
    #[Assert\Email()]
    public string $email = "";

    #[Assert\NotBlank]
    #[Assert\Length(min: 2)]
    public string $motdepasse = "";
}
