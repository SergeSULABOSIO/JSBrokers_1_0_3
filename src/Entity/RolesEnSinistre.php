<?php

namespace App\Entity;

use App\Repository\RolesEnSinistreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: RolesEnSinistreRepository::class)]
class RolesEnSinistre implements OwnerAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    #[ORM\Column(type: Types::ARRAY)]
    #[Groups(['list:read'])]
    private array $accessTypePiece = [];

    #[ORM\Column(type: Types::ARRAY)]
    #[Groups(['list:read'])]
    private array $accessNotification = [];

    #[ORM\Column(type: Types::ARRAY)]
    #[Groups(['list:read'])]
    private array $accessReglement = [];

    #[ORM\ManyToOne(inversedBy: 'rolesEnSinistre')]
    #[Groups(['list:read'])]
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

    public function getAccessTypePiece(): array
    {
        return $this->accessTypePiece;
    }

    public function setAccessTypePiece(array $accessTypePiece): static
    {
        $this->accessTypePiece = $accessTypePiece;

        return $this;
    }

    public function getAccessNotification(): array
    {
        return $this->accessNotification;
    }

    public function setAccessNotification(array $accessNotification): static
    {
        $this->accessNotification = $accessNotification;

        return $this;
    }

    public function getAccessReglement(): array
    {
        return $this->accessReglement;
    }

    public function setAccessReglement(array $accessReglement): static
    {
        $this->accessReglement = $accessReglement;

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
