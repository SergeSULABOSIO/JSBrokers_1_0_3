<?php

namespace App\Entity;

use App\Repository\RolesEnMarketingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RolesEnMarketingRepository::class)]
class RolesEnMarketing implements OwnerAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessPiste = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessTache = [];

    #[ORM\Column(type: Types::ARRAY)]
    private array $accessFeedback = [];

    #[ORM\ManyToOne(inversedBy: 'rolesEnMarketing')]
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

    public function getAccessPiste(): array
    {
        return $this->accessPiste;
    }

    public function setAccessPiste(array $accessPiste): static
    {
        $this->accessPiste = $accessPiste;

        return $this;
    }

    public function getAccessTache(): array
    {
        return $this->accessTache;
    }

    public function setAccessTache(array $accessTache): static
    {
        $this->accessTache = $accessTache;

        return $this;
    }

    public function getAccessFeedback(): array
    {
        return $this->accessFeedback;
    }

    public function setAccessFeedback(array $accessFeedback): static
    {
        $this->accessFeedback = $accessFeedback;

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
