<?php

namespace App\Entity;

use App\Repository\RevenuPourCourtierRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RevenuPourCourtierRepository::class)]
class RevenuPourCourtier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'revenuPourCourtiers')]
    private ?Revenu $type = null;

    #[ORM\Column(nullable: true)]
    private ?float $montantFlatExceptionel = null;

    #[ORM\Column(nullable: true)]
    private ?float $tauxExceptionel = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'revenus')]
    private ?Cotation $cotation = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?Revenu
    {
        return $this->type;
    }

    public function setType(?Revenu $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getMontantFlatExceptionel(): ?float
    {
        return $this->montantFlatExceptionel;
    }

    public function setMontantFlatExceptionel(?float $montantFlatExceptionel): static
    {
        $this->montantFlatExceptionel = $montantFlatExceptionel;

        return $this;
    }

    public function getTauxExceptionel(): ?float
    {
        return $this->tauxExceptionel;
    }

    public function setTauxExceptionel(?float $tauxExceptionel): static
    {
        $this->tauxExceptionel = $tauxExceptionel;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getCotation(): ?Cotation
    {
        return $this->cotation;
    }

    public function setCotation(?Cotation $cotation): static
    {
        $this->cotation = $cotation;

        return $this;
    }
}
