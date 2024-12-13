<?php

namespace App\Entity;

use App\Repository\ChargementRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChargementRepository::class)]
class Chargement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $nom = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?float $montantflat = null;

    #[ORM\Column]
    private ?bool $imposable = null;
    
    #[ORM\Column(nullable: true)]
    private ?float $tauxSurPrimeNette = null;
    
    #[ORM\ManyToOne(inversedBy: 'chargements')]
    private ?Entreprise $entreprise = null;


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getMontantflat(): ?float
    {
        return $this->montantflat;
    }

    public function setMontantflat(?float $montantflat): static
    {
        $this->montantflat = $montantflat;

        return $this;
    }

    public function isImposable(): ?bool
    {
        return $this->imposable;
    }

    public function setImposable(bool $imposable): static
    {
        $this->imposable = $imposable;

        return $this;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): static
    {
        $this->entreprise = $entreprise;

        return $this;
    }

    public function getTauxSurPrimeNette(): ?float
    {
        return $this->tauxSurPrimeNette;
    }

    public function setTauxSurPrimeNette(?float $tauxSurPrimeNette): static
    {
        $this->tauxSurPrimeNette = $tauxSurPrimeNette;

        return $this;
    }
}
