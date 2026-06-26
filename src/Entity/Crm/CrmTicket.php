<?php

namespace App\Entity\Crm;

use App\Entity\Utilisateur;
use App\Repository\Crm\CrmTicketRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @file Ticket de support d'un client (équipe JS Brokers).
 * @description Suivi des demandes : canal, priorité, statut, échéance SLA et
 * satisfaction. Le nombre de tickets ouverts/en retard alimente le critère
 * « Support » du score de santé.
 */
#[ORM\Entity(repositoryClass: CrmTicketRepository::class)]
#[ORM\Table(name: 'crm_ticket')]
#[ORM\HasLifecycleCallbacks]
class CrmTicket
{
    public const STATUT_OUVERT  = 'ouvert';
    public const STATUT_EN_COURS = 'en_cours';
    public const STATUT_RESOLU  = 'resolu';
    public const STATUT_CLOS    = 'clos';

    public const STATUTS = [
        self::STATUT_OUVERT   => 'Ouvert',
        self::STATUT_EN_COURS => 'En cours',
        self::STATUT_RESOLU   => 'Résolu',
        self::STATUT_CLOS     => 'Clos',
    ];

    public const PRIORITE_BASSE   = 'basse';
    public const PRIORITE_NORMALE = 'normale';
    public const PRIORITE_HAUTE   = 'haute';
    public const PRIORITE_URGENTE = 'urgente';

    /** Délais SLA (heures) par priorité. */
    public const SLA_HEURES = [
        self::PRIORITE_BASSE   => 72,
        self::PRIORITE_NORMALE => 48,
        self::PRIORITE_HAUTE   => 24,
        self::PRIORITE_URGENTE => 8,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $client = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $agent = null;

    #[ORM\Column(length: 40)]
    private ?string $reference = null;

    #[ORM\Column(length: 200)]
    private ?string $sujet = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, options: ['default' => 'email'])]
    private string $canal = 'email';

    #[ORM\Column(length: 10, options: ['default' => 'normale'])]
    private string $priorite = self::PRIORITE_NORMALE;

    #[ORM\Column(length: 12, options: ['default' => 'ouvert'])]
    private string $statut = self::STATUT_OUVERT;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $slaDueAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resoluAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $satisfaction = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?Utilisateur
    {
        return $this->client;
    }

    public function setClient(?Utilisateur $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getAgent(): ?Utilisateur
    {
        return $this->agent;
    }

    public function setAgent(?Utilisateur $agent): static
    {
        $this->agent = $agent;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getSujet(): ?string
    {
        return $this->sujet;
    }

    public function setSujet(?string $sujet): static
    {
        $this->sujet = $sujet;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getCanal(): string
    {
        return $this->canal;
    }

    public function setCanal(string $canal): static
    {
        $this->canal = $canal;

        return $this;
    }

    public function getPriorite(): string
    {
        return $this->priorite;
    }

    public function setPriorite(string $priorite): static
    {
        $this->priorite = $priorite;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;
        if (in_array($statut, [self::STATUT_RESOLU, self::STATUT_CLOS], true)) {
            $this->resoluAt ??= new \DateTimeImmutable();
        }

        return $this;
    }

    public function getStatutLabel(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function isOuvert(): bool
    {
        return in_array($this->statut, [self::STATUT_OUVERT, self::STATUT_EN_COURS], true);
    }

    /** Vrai si le SLA est dépassé pour un ticket encore ouvert. */
    public function isSlaDepasse(): bool
    {
        return $this->isOuvert() && $this->slaDueAt !== null && $this->slaDueAt < new \DateTimeImmutable();
    }

    public function getSlaDueAt(): ?\DateTimeImmutable
    {
        return $this->slaDueAt;
    }

    public function setSlaDueAt(?\DateTimeImmutable $slaDueAt): static
    {
        $this->slaDueAt = $slaDueAt;

        return $this;
    }

    public function getResoluAt(): ?\DateTimeImmutable
    {
        return $this->resoluAt;
    }

    public function getSatisfaction(): ?int
    {
        return $this->satisfaction;
    }

    public function setSatisfaction(?int $satisfaction): static
    {
        $this->satisfaction = $satisfaction;

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

    /** Calcule l'échéance SLA depuis la priorité et la date de création. */
    public function computeSla(): void
    {
        $base = $this->createdAt ?? new \DateTimeImmutable();
        $heures = self::SLA_HEURES[$this->priorite] ?? 48;
        $this->slaDueAt = $base->modify('+' . $heures . ' hours');
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        if ($this->slaDueAt === null) {
            $this->computeSla();
        }
        if ($this->reference === null) {
            $this->reference = 'TK-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
