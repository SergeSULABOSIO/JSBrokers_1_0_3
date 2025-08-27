<?php

namespace App\Entity;

use App\Repository\RolesEnFinanceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RolesEnFinanceRepository::class)]
class RolesEnFinance implements OwnerAwareInterface
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

    #[ORM\Column(type: Types::ARRAY, nullable: true)]
    private ?array $accessTypeRevenu = null;

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessTranche = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessTypeChargement = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessNote = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessPaiement = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessBordereau = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessRevenu = [];

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

    public function getAccessTypeRevenu(): ?array
    {
        return $this->accessTypeRevenu;
    }

    public function setAccessTypeRevenu(?array $accessTypeRevenu): static
    {
        $this->accessTypeRevenu = $accessTypeRevenu;

        return $this;
    }

    public function getAccessTranche(): array
    {
        return $this->accessTranche;
    }

    public function setAccessTranche(array $accessTranche): static
    {
        $this->accessTranche = $accessTranche;

        return $this;
    }

    public function getAccessTypeChargement(): array
    {
        return $this->accessTypeChargement;
    }

    public function setAccessTypeChargement(array $accessTypeChargement): static
    {
        $this->accessTypeChargement = $accessTypeChargement;

        return $this;
    }

    public function getAccessNote(): array
    {
        return $this->accessNote;
    }

    public function setAccessNote(array $accessNote): static
    {
        $this->accessNote = $accessNote;

        return $this;
    }

    public function getAccessPaiement(): array
    {
        return $this->accessPaiement;
    }

    public function setAccessPaiement(array $accessPaiement): static
    {
        $this->accessPaiement = $accessPaiement;

        return $this;
    }

    public function getAccessBordereau(): array
    {
        return $this->accessBordereau;
    }

    public function setAccessBordereau(array $accessBordereau): static
    {
        $this->accessBordereau = $accessBordereau;

        return $this;
    }

    public function getAccessRevenu(): array
    {
        return $this->accessRevenu;
    }

    public function setAccessRevenu(array $accessRevenu): static
    {
        $this->accessRevenu = $accessRevenu;

        return $this;
    }
}
