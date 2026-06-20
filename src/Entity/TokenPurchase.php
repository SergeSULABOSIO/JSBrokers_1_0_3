<?php

namespace App\Entity;

use App\Repository\TokenPurchaseRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @file Achat d'un paquet de tokens (paiement simulé pour l'instant).
 * @description Trace chaque approvisionnement : paquet choisi, tokens crédités,
 * montant payé, 4 derniers chiffres de la carte (le numéro complet n'est JAMAIS
 * stocké) et référence. Alimente l'e-mail de confirmation et l'historique.
 */
#[ORM\Entity(repositoryClass: TokenPurchaseRepository::class)]
#[ORM\HasLifecycleCallbacks]
class TokenPurchase
{
    public const STATUS_PAID_SIMULATED = 'paid_simulated';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $utilisateur = null;

    /** Identifiant technique du paquet (cf. TokenPricing::PACKS). */
    #[ORM\Column(length: 50)]
    #[Groups(['list:read'])]
    private ?string $pack = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private int $tokens = 0;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private float $montantUsd = 0.0;

    #[ORM\Column(length: 4, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $cardLast4 = null;

    #[ORM\Column(length: 40)]
    #[Groups(['list:read'])]
    private ?string $reference = null;

    #[ORM\Column(length: 30)]
    #[Groups(['list:read'])]
    private string $status = self::STATUS_PAID_SIMULATED;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    public function getPack(): ?string
    {
        return $this->pack;
    }

    public function setPack(string $pack): static
    {
        $this->pack = $pack;

        return $this;
    }

    public function getTokens(): int
    {
        return $this->tokens;
    }

    public function setTokens(int $tokens): static
    {
        $this->tokens = $tokens;

        return $this;
    }

    public function getMontantUsd(): float
    {
        return $this->montantUsd;
    }

    public function setMontantUsd(float $montantUsd): static
    {
        $this->montantUsd = $montantUsd;

        return $this;
    }

    public function getCardLast4(): ?string
    {
        return $this->cardLast4;
    }

    public function setCardLast4(?string $cardLast4): static
    {
        $this->cardLast4 = $cardLast4;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

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
