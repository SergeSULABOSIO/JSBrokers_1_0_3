<?php

namespace App\Entity;

use App\Repository\CouponRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * @file Coupon / offre de réduction périodique sur l'achat de paquets de tokens.
 * @description Remise en pourcentage ou en montant fixe (USD), valable sur une
 * période donnée, éventuellement limitée en nombre d'usages et ciblant un paquet
 * précis. Créé et géré par l'équipe JS Brokers depuis la Console.
 */
#[ORM\Entity(repositoryClass: CouponRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['code'], message: 'coupon.code_already_used')]
class Coupon
{
    /** Remise exprimée en pourcentage du prix (0–100). */
    public const TYPE_PERCENT = 'percent';
    /** Remise exprimée en montant fixe (USD). */
    public const TYPE_FIXED = 'fixed';

    public const TYPES = [self::TYPE_PERCENT, self::TYPE_FIXED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 40, unique: true)]
    #[Groups(['list:read'])]
    private ?string $code = null;

    #[ORM\Column(length: 10)]
    #[Groups(['list:read'])]
    private string $type = self::TYPE_PERCENT;

    /** Valeur de la remise : pourcentage (si percent) ou montant USD (si fixed). */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private float $valeur = 0.0;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $dateFin = null;

    /** Nombre maximum d'utilisations (null = illimité). */
    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?int $usageLimit = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private int $usageCount = 0;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private bool $actif = true;

    /** Clé du paquet ciblé (null = applicable à tous les paquets). */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $packCible = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        // Normalisation : code en majuscules, sans espaces superflus.
        $this->code = $code === null ? null : strtoupper(trim($code));

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getValeur(): float
    {
        return $this->valeur;
    }

    public function setValeur(float $valeur): static
    {
        $this->valeur = $valeur;

        return $this;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getUsageLimit(): ?int
    {
        return $this->usageLimit;
    }

    public function setUsageLimit(?int $usageLimit): static
    {
        $this->usageLimit = $usageLimit;

        return $this;
    }

    public function getUsageCount(): int
    {
        return $this->usageCount;
    }

    public function setUsageCount(int $usageCount): static
    {
        $this->usageCount = $usageCount;

        return $this;
    }

    public function incrementUsage(): static
    {
        $this->usageCount++;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    public function getPackCible(): ?string
    {
        return $this->packCible;
    }

    public function setPackCible(?string $packCible): static
    {
        $this->packCible = $packCible;

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

    /**
     * Le coupon est-il utilisable maintenant ? (actif, dans sa période de validité
     * et sous sa limite d'usage). Le ciblage de paquet est vérifié à part.
     */
    public function isValideMaintenant(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        if (!$this->actif) {
            return false;
        }
        if ($this->dateDebut !== null && $now < $this->dateDebut) {
            return false;
        }
        if ($this->dateFin !== null && $now > $this->dateFin) {
            return false;
        }
        if ($this->usageLimit !== null && $this->usageCount >= $this->usageLimit) {
            return false;
        }

        return true;
    }

    /** Le coupon s'applique-t-il au paquet donné ? (packCible null = tous). */
    public function estApplicableAuPack(string $packKey): bool
    {
        return $this->packCible === null || $this->packCible === $packKey;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }
}
