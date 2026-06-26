<?php

namespace App\Entity\Crm;

use App\Entity\Utilisateur;
use App\Repository\Crm\CrmTacheRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @file Tâche interne de l'équipe JS Brokers (relance, démo, réactivation…).
 * @description To-do des commerciaux / Customer Success, éventuellement rattachée
 * à un client. Créée manuellement OU automatiquement (automatisations). Distincte
 * de App\Entity\Tache (workspace du courtier). L'idempotence des tâches
 * automatiques est gérée via la clé `cleAuto` (une tâche auto par clé).
 */
#[ORM\Entity(repositoryClass: CrmTacheRepository::class)]
#[ORM\Table(name: 'crm_tache')]
#[ORM\HasLifecycleCallbacks]
class CrmTache
{
    public const STATUT_A_FAIRE = 'a_faire';
    public const STATUT_FAITE   = 'faite';

    public const PRIORITE_BASSE   = 'basse';
    public const PRIORITE_NORMALE = 'normale';
    public const PRIORITE_HAUTE   = 'haute';

    public const ORIGINE_MANUELLE = 'manuelle';
    public const ORIGINE_AUTO     = 'auto';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private ?string $titre = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Client concerné (optionnel : certaines tâches sont transverses). */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Utilisateur $client = null;

    /** Agent JS Brokers à qui la tâche est assignée. */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $assigneA = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(length: 10, options: ['default' => 'normale'])]
    private string $priorite = self::PRIORITE_NORMALE;

    #[ORM\Column(length: 10, options: ['default' => 'a_faire'])]
    private string $statut = self::STATUT_A_FAIRE;

    #[ORM\Column(length: 10, options: ['default' => 'manuelle'])]
    private string $origine = self::ORIGINE_MANUELLE;

    /** Clé d'idempotence pour les tâches automatiques (unique si renseignée). */
    #[ORM\Column(length: 120, nullable: true, unique: true)]
    private ?string $cleAuto = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = $titre;

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

    public function getClient(): ?Utilisateur
    {
        return $this->client;
    }

    public function setClient(?Utilisateur $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getAssigneA(): ?Utilisateur
    {
        return $this->assigneA;
    }

    public function setAssigneA(?Utilisateur $assigneA): static
    {
        $this->assigneA = $assigneA;

        return $this;
    }

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function setDueAt(?\DateTimeImmutable $dueAt): static
    {
        $this->dueAt = $dueAt;

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
        $this->closedAt = $statut === self::STATUT_FAITE ? ($this->closedAt ?? new \DateTimeImmutable()) : null;

        return $this;
    }

    public function isFaite(): bool
    {
        return $this->statut === self::STATUT_FAITE;
    }

    /** Vrai si la tâche est en retard (échéance passée et non faite). */
    public function isEnRetard(): bool
    {
        return !$this->isFaite() && $this->dueAt !== null && $this->dueAt < new \DateTimeImmutable();
    }

    public function getOrigine(): string
    {
        return $this->origine;
    }

    public function setOrigine(string $origine): static
    {
        $this->origine = $origine;

        return $this;
    }

    public function getCleAuto(): ?string
    {
        return $this->cleAuto;
    }

    public function setCleAuto(?string $cleAuto): static
    {
        $this->cleAuto = $cleAuto;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
