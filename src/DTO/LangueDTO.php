<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class LangueDTO
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 2)]
    public string $language = "";
}
