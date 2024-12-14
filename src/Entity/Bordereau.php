<?php

namespace App\Entity;

use App\Repository\BordereauRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BordereauRepository::class)]
class Bordereau
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\ManyToOne(inversedBy: 'bordereaus')]
    private ?Assureur $assureur = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $receivedAt = null;

    #[ORM\Column]
    private ?float $montantTTC = null;

    #[ORM\ManyToOne(inversedBy: 'bordereaus')]
    private ?Invite $invite = null;

    #[ORM\ManyToOne(inversedBy: 'bordereaux')]
    private ?FactureCommission $factureCommission = null;

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

    public function getAssureur(): ?Assureur
    {
        return $this->assureur;
    }

    public function setAssureur(?Assureur $assureur): static
    {
        $this->assureur = $assureur;

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

    public function getReceivedAt(): ?\DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(\DateTimeImmutable $receivedAt): static
    {
        $this->receivedAt = $receivedAt;

        return $this;
    }

    public function getMontantTTC(): ?float
    {
        return $this->montantTTC;
    }

    public function setMontantTTC(float $montantTTC): static
    {
        $this->montantTTC = $montantTTC;

        return $this;
    }

    public function getInvite(): ?Invite
    {
        return $this->invite;
    }

    public function setInvite(?Invite $invite): static
    {
        $this->invite = $invite;

        return $this;
    }

    public function getFactureCommission(): ?FactureCommission
    {
        return $this->factureCommission;
    }

    public function setFactureCommission(?FactureCommission $factureCommission): static
    {
        $this->factureCommission = $factureCommission;

        return $this;
    }
}
