<?php

namespace App\Entity;

use App\Enum\ObjectifMode;
use App\Repository\ObjectifRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @file Objectif (SMART) fixé à un collaborateur pour une période donnée.
 * @description Le super-admin définit une cible mesurable ; l'atteinte est suivie
 * soit manuellement (valeur saisie en revue), soit automatiquement (métrique
 * système recalculée par EvaluationMetricProvider). Le poids pondère l'objectif
 * dans le score global de la fiche d'évaluation (cf. FicheEvaluationBuilder).
 */
#[ORM\Entity(repositoryClass: ObjectifRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Objectif
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $collaborateur = null;

    /** Année de la période d'évaluation. */
    #[ORM\Column]
    private int $annee;

    /** Trimestre : 0 = objectif annuel, 1–4 = trimestriel. */
    #[ORM\Column(options: ['default' => 0])]
    private int $trimestre = 0;

    #[ORM\Column(length: 180)]
    private ?string $titre = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Valeur cible à atteindre. */
    #[ORM\Column]
    private float $cible = 0.0;

    /** Unité de mesure (ex. « ventes », « tickets », « % »). */
    #[ORM\Column(length: 40, options: ['default' => ''])]
    private string $unite = '';

    /** Poids de l'objectif dans le score global (en %). */
    #[ORM\Column(options: ['default' => 25])]
    private int $poids = 25;

    #[ORM\Column(length: 20, enumType: ObjectifMode::class, options: ['default' => 'manuel'])]
    private ObjectifMode $mode = ObjectifMode::MANUEL;

    /** Clé de métrique système (si mode AUTO). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $metrique = null;

    /** Valeur atteinte saisie manuellement (si mode MANUEL). */
    #[ORM\Column(nullable: true)]
    private ?float $valeurManuelle = null;

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

    public function getCible(): float
    {
        return $this->cible;
    }

    public function setCible(float $cible): static
    {
        $this->cible = $cible;

        return $this;
    }

    public function getUnite(): string
    {
        return $this->unite;
    }

    public function setUnite(?string $unite): static
    {
        $this->unite = trim((string) $unite);

        return $this;
    }

    public function getPoids(): int
    {
        return $this->poids;
    }

    public function setPoids(int $poids): static
    {
        $this->poids = max(0, $poids);

        return $this;
    }

    public function getMode(): ObjectifMode
    {
        return $this->mode;
    }

    public function setMode(ObjectifMode $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function getMetrique(): ?string
    {
        return $this->metrique;
    }

    public function setMetrique(?string $metrique): static
    {
        $this->metrique = $metrique;

        return $this;
    }

    public function getValeurManuelle(): ?float
    {
        return $this->valeurManuelle;
    }

    public function setValeurManuelle(?float $valeurManuelle): static
    {
        $this->valeurManuelle = $valeurManuelle;

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

    /** Pourcentage d'atteinte (0–100) pour une valeur atteinte donnée. */
    public function pourcentagePour(float $atteinte): int
    {
        if ($this->cible <= 0.0) {
            return 0;
        }

        return (int) round(min(100.0, max(0.0, $atteinte / $this->cible * 100)));
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
