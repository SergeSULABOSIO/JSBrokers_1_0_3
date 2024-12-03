<?php

namespace App\DTO;

use App\Entity\Entreprise;
use Doctrine\Common\Collections\Collection;

class CriteresRechercheDashBordDTO
{
    /**
     * @var Collection<int, Entreprise>
     */
    public Collection $entreprises;
}
