<?php

namespace App\Entity;

use App\Repository\RolesEnProductionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RolesEnProductionRepository::class)]
class RolesEnProduction implements OwnerAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessGroupe = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessClient = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessAssureur = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessContact = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessRisque = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessAvenant = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessPartenaire = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessCotation = [];

    #[ORM\ManyToOne(inversedBy: 'rolesEnProduction')]
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

    public function getAccessGroupe(): array
    {
        return $this->accessGroupe;
    }

    public function setAccessGroupe(array $accessGroupe): static
    {
        $this->accessGroupe = $accessGroupe;

        return $this;
    }

    public function getAccessClient(): array
    {
        return $this->accessClient;
    }

    public function setAccessClient(array $accessClient): static
    {
        $this->accessClient = $accessClient;

        return $this;
    }

    public function getAccessAssureur(): array
    {
        return $this->accessAssureur;
    }

    public function setAccessAssureur(array $accessAssureur): static
    {
        $this->accessAssureur = $accessAssureur;

        return $this;
    }

    public function getAccessContact(): array
    {
        return $this->accessContact;
    }

    public function setAccessContact(array $accessContact): static
    {
        $this->accessContact = $accessContact;

        return $this;
    }

    public function getAccessRisque(): array
    {
        return $this->accessRisque;
    }

    public function setAccessRisque(array $accessRisque): static
    {
        $this->accessRisque = $accessRisque;

        return $this;
    }

    public function getAccessAvenant(): array
    {
        return $this->accessAvenant;
    }

    public function setAccessAvenant(array $accessAvenant): static
    {
        $this->accessAvenant = $accessAvenant;

        return $this;
    }

    public function getAccessPartenaire(): array
    {
        return $this->accessPartenaire;
    }

    public function setAccessPartenaire(array $accessPartenaire): static
    {
        $this->accessPartenaire = $accessPartenaire;

        return $this;
    }

    public function getAccessCotation(): array
    {
        return $this->accessCotation;
    }

    public function setAccessCotation(array $accessCotation): static
    {
        $this->accessCotation = $accessCotation;

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
