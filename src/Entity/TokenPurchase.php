<?php

namespace App\Entity;

use App\Payment\PaymentStatus;
use App\Repository\TokenPurchaseRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @file Achat d'un paquet de tokens (cycle de vie de paiement réel, PSP-agnostique).
 * @description Trace chaque approvisionnement : paquet choisi, tokens crédités,
 * montant payé, 4 derniers chiffres de la carte (le numéro complet n'est JAMAIS
 * stocké), référence lisible, prestataire et référence prestataire (clé
 * d'idempotence), statut du paiement et numéro de facture. Alimente l'e-mail de
 * confirmation, la facture PDF et l'historique.
 */
#[ORM\Entity(repositoryClass: TokenPurchaseRepository::class)]
#[ORM\HasLifecycleCallbacks]
class TokenPurchase
{
    /** Cycle de vie d'un paiement (cf. App\Payment\PaymentStatus — source unique). */
    public const STATUS_PENDING = PaymentStatus::PENDING;
    public const STATUS_PAID = PaymentStatus::PAID;
    public const STATUS_FAILED = PaymentStatus::FAILED;
    public const STATUS_REFUNDED = PaymentStatus::REFUNDED;

    /** Statut historique des achats simulés antérieurs au paiement réel (legacy). */
    public const STATUS_PAID_SIMULATED = 'paid_simulated';

    /** Statuts comptés comme chiffre d'affaires encaissé (paiement abouti). */
    public const STATUSES_REVENUE = [self::STATUS_PAID, self::STATUS_PAID_SIMULATED];

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

    /** Remise appliquée (USD) via un coupon ; 0 si aucun coupon. */
    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['list:read'])]
    private float $remiseUsd = 0.0;

    /** Code du coupon utilisé pour cet achat (null si aucun) — traçabilité. */
    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $couponCode = null;

    #[ORM\Column(length: 4, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $cardLast4 = null;

    #[ORM\Column(length: 40)]
    #[Groups(['list:read'])]
    private ?string $reference = null;

    #[ORM\Column(length: 30)]
    #[Groups(['list:read'])]
    private string $status = self::STATUS_PENDING;

    /** Identifiant technique du PSP ayant traité l'achat (ex. « simulated »). */
    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $provider = null;

    /** Référence du paiement chez le PSP — clé d'idempotence (webhook + retour). */
    #[ORM\Column(length: 120, nullable: true, unique: true)]
    #[Groups(['list:read'])]
    private ?string $providerReference = null;

    /** Numéro de facture séquentiel (FAC-AAAA-NNNNN), attribué à l'encaissement. */
    #[ORM\Column(length: 30, nullable: true, unique: true)]
    #[Groups(['list:read'])]
    private ?string $invoiceNumber = null;

    /** Date d'encaissement effectif (passage au statut PAID). */
    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $paidAt = null;

    /** Date de remboursement (passage au statut REFUNDED). */
    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $refundedAt = null;

    /** Motif d'échec renvoyé par le PSP (clé traduisible ou message), si FAILED. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $failedReason = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    /** L'achat est-il encaissé (paiement réel abouti ou achat simulé legacy) ? */
    public function isPaid(): bool
    {
        return in_array($this->status, self::STATUSES_REVENUE, true);
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

    public function getRemiseUsd(): float
    {
        return $this->remiseUsd;
    }

    public function setRemiseUsd(float $remiseUsd): static
    {
        $this->remiseUsd = $remiseUsd;

        return $this;
    }

    public function getCouponCode(): ?string
    {
        return $this->couponCode;
    }

    public function setCouponCode(?string $couponCode): static
    {
        $this->couponCode = $couponCode;

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

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getProviderReference(): ?string
    {
        return $this->providerReference;
    }

    public function setProviderReference(?string $providerReference): static
    {
        $this->providerReference = $providerReference;

        return $this;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function setInvoiceNumber(?string $invoiceNumber): static
    {
        $this->invoiceNumber = $invoiceNumber;

        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getRefundedAt(): ?\DateTimeImmutable
    {
        return $this->refundedAt;
    }

    public function setRefundedAt(?\DateTimeImmutable $refundedAt): static
    {
        $this->refundedAt = $refundedAt;

        return $this;
    }

    public function getFailedReason(): ?string
    {
        return $this->failedReason;
    }

    public function setFailedReason(?string $failedReason): static
    {
        $this->failedReason = $failedReason;

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
