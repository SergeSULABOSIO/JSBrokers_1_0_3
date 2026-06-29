<?php

namespace App\Entity;

use App\Repository\EvaluationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @file Fiche d'évaluation d'un collaborateur pour une période (année + trimestre).
 * @description Porte le qualitatif (appréciation du super-admin) et la clôture. Le
 * score est calculé à la volée par FicheEvaluationBuilder à partir des objectifs ;
 * il n'est figé (scoreFige) qu'à la clôture, pour conserver l'historique stable.
 */
#[ORM\Entity(repositoryClass: EvaluationRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_EVAL_PERIODE', columns: ['collaborateur_id', 'annee', 'trimestre'])]
#[ORM\HasLifecycleCallbacks]
class Evaluation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $collaborateur = null;

    #[ORM\Column]
    private int $annee;

    #[ORM\Column(options: ['default' => 0])]
    private int $trimestre = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $appreciation = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $cloturee = false;

    /** Score global pondéré figé à la clôture (0–100). */
    #[ORM\Column(nullable: true)]
    private ?float $scoreFige = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCollaborateur(): ?Utilisateur
    {
        return $this->collaborateur;
    }

    public function setCollaborateur(?Utilisateur $collaborateur): static
    {
        $this->collaborateur = $collaborateur;

        return $this;
    }

    public function getAnnee(): int
    {
        return $this->annee;
    }

    public function setAnnee(int $annee): static
    {
        $this->annee = $annee;

        return $this;
    }

    public function getTrimestre(): int
    {
        return $this->trimestre;
    }

    public function setTrimestre(int $trimestre): static
    {
        $this->trimestre = max(0, min(4, $trimestre));

        return $this;
    }

    public function getAppreciation(): ?string
    {
        return $this->appreciation;
    }

    public function setAppreciation(?string $appreciation): static
    {
        $this->appreciation = $appreciation;

        return $this;
    }

    public function isCloturee(): bool
    {
        return $this->cloturee;
    }

    public function setCloturee(bool $cloturee): static
    {
        $this->cloturee = $cloturee;

        return $this;
    }

    public function getScoreFige(): ?float
    {
        return $this->scoreFige;
    }

    public function setScoreFige(?float $scoreFige): static
    {
        $this->scoreFige = $scoreFige;

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

    /** Libellé de la période (« 2026 · Annuel » ou « 2026 · T2 »). */
    public function periodeLabel(): string
    {
        return $this->annee . ' · ' . ($this->trimestre === 0 ? 'Annuel' : 'T' . $this->trimestre);
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
