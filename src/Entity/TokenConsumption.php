<?php

namespace App\Entity;

use App\Repository\TokenConsumptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @file Journal détaillé d'une consommation de tokens.
 * @description Une ligne par opération métrée (écriture = 1 entité ; lecture =
 * lot de N entités). Alimente le tableau « détails de consommation » de
 * l'espace compte. Le coût USD n'est pas stocké : il est recalculé à
 * l'affichage via TokenPricing::costUsd() pour rester ajustable.
 */
#[ORM\Entity(repositoryClass: TokenConsumptionRepository::class)]
#[ORM\HasLifecycleCallbacks]
class TokenConsumption
{
    public const SENS_ENTREE = 'entree';
    public const SENS_SORTIE = 'sortie';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    /** Entreprise dans laquelle la consommation a eu lieu. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entreprise $entreprise = null;

    /** Propriétaire dont le solde a été débité (payeur). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $proprietaire = null;

    /** Collaborateur ayant réellement effectué l'opération (peut être le propriétaire lui-même). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $acteur = null;

    /** Nom lisible de l'entité concernée (ex. « Cotation »). */
    #[ORM\Column(length: 100)]
    #[Groups(['list:read'])]
    private ?string $entiteNom = null;

    /** Sens de la consommation : entree (écriture) ou sortie (lecture). */
    #[ORM\Column(length: 10)]
    #[Groups(['list:read'])]
    private ?string $sens = null;

    /** Nombre d'entités concernées par l'opération. */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private int $nombre = 1;

    /** Poids unitaire en tokens. */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private int $poidsUnitaire = 0;

    /** Poids total en tokens (nombre × poidsUnitaire). */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private int $poidsTotal = 0;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getProprietaire(): ?Utilisateur
    {
        return $this->proprietaire;
    }

    public function setProprietaire(?Utilisateur $proprietaire): static
    {
        $this->proprietaire = $proprietaire;

        return $this;
    }

    public function getActeur(): ?Utilisateur
    {
        return $this->acteur;
    }

    public function setActeur(?Utilisateur $acteur): static
    {
        $this->acteur = $acteur;

        return $this;
    }

    public function getEntiteNom(): ?string
    {
        return $this->entiteNom;
    }

    public function setEntiteNom(string $entiteNom): static
    {
        $this->entiteNom = $entiteNom;

        return $this;
    }

    public function getSens(): ?string
    {
        return $this->sens;
    }

    public function setSens(string $sens): static
    {
        $this->sens = $sens;

        return $this;
    }

    public function getNombre(): int
    {
        return $this->nombre;
    }

    public function setNombre(int $nombre): static
    {
        $this->nombre = $nombre;

        return $this;
    }

    public function getPoidsUnitaire(): int
    {
        return $this->poidsUnitaire;
    }

    public function setPoidsUnitaire(int $poidsUnitaire): static
    {
        $this->poidsUnitaire = $poidsUnitaire;

        return $this;
    }

    public function getPoidsTotal(): int
    {
        return $this->poidsTotal;
    }

    public function setPoidsTotal(int $poidsTotal): static
    {
        $this->poidsTotal = $poidsTotal;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }
}
