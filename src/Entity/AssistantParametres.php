<?php

namespace App\Entity;

use App\Repository\AssistantParametresRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paramètres de l'assistant IA d'une entreprise : le personnage est unique par
 * entreprise (une seule ligne possible) et porte le nom choisi par le cabinet.
 * Tant qu'aucune ligne n'existe, le nom de repli NOM_PAR_DEFAUT est utilisé.
 */
#[ORM\Entity(repositoryClass: AssistantParametresRepository::class)]
#[ORM\HasLifecycleCallbacks]
class AssistantParametres
{
    public const NOM_PAR_DEFAUT = 'Ket';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Entreprise $entreprise = null;

    #[ORM\Column(length: 60)]
    private ?string $nom = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): self
    {
        $this->entreprise = $entreprise;
        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable('now');
        $this->updatedAt = new \DateTimeImmutable('now');
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now');
    }
}
