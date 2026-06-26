<?php

namespace App\Entity;

use App\Repository\PlateformeParametresRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * @file Paramètres globaux de la plateforme JS Brokers (plan tarifaire des tokens).
 * @description Ligne UNIQUE (singleton, id=1) éditable depuis la Console par le
 * super-admin. Rend configurable ce qui était figé dans App\Token\TokenPricing :
 * paquets prépayés, allocation gratuite, fenêtre de renouvellement, poids
 * d'écriture/lecture et taux USD/token.
 *
 * Tous les champs sont NULLABLE : une valeur nulle signifie « utiliser la
 * constante par défaut de TokenPricing ». ParametresTokenService applique ce
 * repli champ par champ, garantissant un comportement identique tant qu'aucune
 * valeur n'a été personnalisée (zéro régression).
 */
#[ORM\Entity(repositoryClass: PlateformeParametresRepository::class)]
#[ORM\HasLifecycleCallbacks]
class PlateformeParametres
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Paquets prépayés : { "clé": { "tokens": int, "price": float } }. */
    #[ORM\Column(nullable: true)]
    private ?array $packs = null;

    /** Allocation gratuite offerte par fenêtre. */
    #[ORM\Column(nullable: true)]
    private ?int $freeAllowance = null;

    /** Durée (heures) de validité de l'allocation gratuite avant renouvellement. */
    #[ORM\Column(nullable: true)]
    private ?int $freeWindowHours = null;

    /** Poids en tokens d'une entité envoyée au frontend (lecture). */
    #[ORM\Column(nullable: true)]
    private ?int $readWeight = null;

    /** Poids par défaut en écriture pour toute entité non explicitement listée. */
    #[ORM\Column(nullable: true)]
    private ?int $defaultWriteWeight = null;

    /** Poids d'écriture par entité : { "App\\Entity\\Cotation": int, ... }. */
    #[ORM\Column(nullable: true)]
    private ?array $writeWeights = null;

    /** Taux de référence USD par token (informatif). */
    #[ORM\Column(nullable: true)]
    private ?float $usdPerToken = null;

    /**
     * Capital social de JS Brokers (USD). Apport d'ouverture des actionnaires :
     * génère l'écriture fondatrice (D 521 Banques / C 101 Capital social) qui
     * alimente la trésorerie et les capitaux propres dans les documents comptables.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    private ?string $capitalSocial = null;

    /** Date de constitution (date de l'apport en capital). À défaut : 1ʳᵉ opération. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateConstitution = null;

    /**
     * Poids des critères du score de santé CRM : { "engagement": int, ... }.
     * NULL → repli sur App\Crm\CrmHealthScoreService::WEIGHTS.
     */
    #[ORM\Column(nullable: true)]
    private ?array $crmHealthWeights = null;

    /**
     * Seuils de couleur du score CRM : { "vert": 75, "jaune": 50, "orange": 25 }.
     * NULL → repli sur les seuils par défaut.
     */
    #[ORM\Column(nullable: true)]
    private ?array $crmThresholds = null;

    /**
     * Paramètres d'automatisation CRM : { "inactiviteJours": 15, "soldeBas": 1000,
     * "churnJours": 45 }. NULL → repli sur les constantes par défaut.
     */
    #[ORM\Column(nullable: true)]
    private ?array $crmAutomation = null;

    /**
     * Dernière exécution des automatisations CRM planifiées (déclenchement
     * paresseux throttlé). NULL = jamais exécutées.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $crmLastAutoRunAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCrmLastAutoRunAt(): ?\DateTimeImmutable
    {
        return $this->crmLastAutoRunAt;
    }

    public function setCrmLastAutoRunAt(?\DateTimeImmutable $crmLastAutoRunAt): static
    {
        $this->crmLastAutoRunAt = $crmLastAutoRunAt;

        return $this;
    }

    public function getCrmHealthWeights(): ?array
    {
        return $this->crmHealthWeights;
    }

    public function setCrmHealthWeights(?array $crmHealthWeights): static
    {
        $this->crmHealthWeights = $crmHealthWeights;

        return $this;
    }

    public function getCrmThresholds(): ?array
    {
        return $this->crmThresholds;
    }

    public function setCrmThresholds(?array $crmThresholds): static
    {
        $this->crmThresholds = $crmThresholds;

        return $this;
    }

    public function getCrmAutomation(): ?array
    {
        return $this->crmAutomation;
    }

    public function setCrmAutomation(?array $crmAutomation): static
    {
        $this->crmAutomation = $crmAutomation;

        return $this;
    }

    public function getPacks(): ?array
    {
        return $this->packs;
    }

    public function setPacks(?array $packs): static
    {
        $this->packs = $packs;

        return $this;
    }

    public function getFreeAllowance(): ?int
    {
        return $this->freeAllowance;
    }

    public function setFreeAllowance(?int $freeAllowance): static
    {
        $this->freeAllowance = $freeAllowance;

        return $this;
    }

    public function getFreeWindowHours(): ?int
    {
        return $this->freeWindowHours;
    }

    public function setFreeWindowHours(?int $freeWindowHours): static
    {
        $this->freeWindowHours = $freeWindowHours;

        return $this;
    }

    public function getReadWeight(): ?int
    {
        return $this->readWeight;
    }

    public function setReadWeight(?int $readWeight): static
    {
        $this->readWeight = $readWeight;

        return $this;
    }

    public function getDefaultWriteWeight(): ?int
    {
        return $this->defaultWriteWeight;
    }

    public function setDefaultWriteWeight(?int $defaultWriteWeight): static
    {
        $this->defaultWriteWeight = $defaultWriteWeight;

        return $this;
    }

    public function getWriteWeights(): ?array
    {
        return $this->writeWeights;
    }

    public function setWriteWeights(?array $writeWeights): static
    {
        $this->writeWeights = $writeWeights;

        return $this;
    }

    public function getUsdPerToken(): ?float
    {
        return $this->usdPerToken;
    }

    public function setUsdPerToken(?float $usdPerToken): static
    {
        $this->usdPerToken = $usdPerToken;

        return $this;
    }

    public function getCapitalSocial(): ?string
    {
        return $this->capitalSocial;
    }

    public function setCapitalSocial(?string $capitalSocial): static
    {
        // Champ vide → null (pas de capital social saisi).
        $this->capitalSocial = ($capitalSocial === null || $capitalSocial === '') ? null : $capitalSocial;

        return $this;
    }

    /** Capital social exploitable pour le calcul (0 si non renseigné). */
    public function getCapitalSocialFloat(): float
    {
        return (float) $this->capitalSocial;
    }

    public function getDateConstitution(): ?\DateTimeImmutable
    {
        return $this->dateConstitution;
    }

    public function setDateConstitution(?\DateTimeImmutable $dateConstitution): static
    {
        $this->dateConstitution = $dateConstitution;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
