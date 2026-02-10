<?php

namespace App\Entity;

use App\Repository\RolesEnAdministrationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: RolesEnAdministrationRepository::class)]
class RolesEnAdministration implements OwnerAwareInterface
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
    private array $accessDocument = [];

    #[ORM\Column(type: Types::ARRAY)]
    #[Groups(['list:read'])]
    private array $accessClasseur = [];

    #[ORM\Column(type: Types::ARRAY)]
    #[Groups(['list:read'])]
    private array $accessInvite = [];

    #[ORM\ManyToOne(inversedBy: 'rolesEnAdministration')]
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

    public function getAccessDocument(): array
    {
        return $this->accessDocument;
    }

    public function setAccessDocument(array $accessDocument): static
    {
        $this->accessDocument = $accessDocument;

        return $this;
    }

    public function getAccessClasseur(): array
    {
        return $this->accessClasseur;
    }

    public function setAccessClasseur(array $accessClasseur): static
    {
        $this->accessClasseur = $accessClasseur;

        return $this;
    }

    public function getAccessInvite(): array
    {
        return $this->accessInvite;
    }

    public function setAccessInvite(array $accessInvite): static
    {
        $this->accessInvite = $accessInvite;

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
