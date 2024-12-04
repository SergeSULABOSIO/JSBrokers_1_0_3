<?php

namespace App\DTO;

use App\Entity\Entreprise;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;

class CriteresRechercheDashBordDTO
{
    /**
     * @var Collection<int, Entreprise>
     */
    private Collection $entreprises;

    /**
     * @var Collection<int, Entreprise>
     */
    private Collection $assureurs;

    /**
     * @var Collection<int, Entreprise>
     */
    private Collection $produits;

    /**
     * @var Collection<int, Entreprise>
     */
    private Collection $partenaires;

    private DateTimeImmutable $dateDebut;

    private DateTimeImmutable $dateFin;
}
