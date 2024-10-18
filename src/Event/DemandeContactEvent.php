<?php

namespace App\Event;

use App\DTO\DemandeContactDTO;

class DemandeContactEvent
{
    public function __construct(
        public readonly DemandeContactDTO $data
    ) {}
}
