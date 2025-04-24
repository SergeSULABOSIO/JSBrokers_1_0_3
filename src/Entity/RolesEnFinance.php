<?php

namespace App\Entity;

use App\Repository\RolesEnFinanceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RolesEnFinanceRepository::class)]
class RolesEnFinance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessMonnaie = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessCompteBancaire = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessTaxe = [];

    #[ORM\ManyToOne(inversedBy: 'rolesEnFinance')]
    private ?Invite $invite = null;

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

    public function getAccessMonnaie(): array
    {
        return $this->accessMonnaie;
    }

    public function setAccessMonnaie(array $accessMonnaie): static
    {
        $this->accessMonnaie = $accessMonnaie;

        return $this;
    }

    public function getAccessCompteBancaire(): array
    {
        return $this->accessCompteBancaire;
    }

    public function setAccessCompteBancaire(array $accessCompteBancaire): static
    {
        $this->accessCompteBancaire = $accessCompteBancaire;

        return $this;
    }

    public function getAccessTaxe(): array
    {
        return $this->accessTaxe;
    }

    public function setAccessTaxe(array $accessTaxe): static
    {
        $this->accessTaxe = $accessTaxe;

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
}
