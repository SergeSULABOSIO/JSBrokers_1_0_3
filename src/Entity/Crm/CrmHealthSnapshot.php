<?php

namespace App\Entity\Crm;

use App\Entity\Utilisateur;
use App\Repository\Crm\CrmHealthSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @file Instantané quotidien du score de santé d'un client.
 * @description Conserve l'historique du score (et son détail par critère) pour
 * tracer la tendance (courbes) et déclencher des automatisations sur variation.
 * Alimenté par la commande app:crm:sync (cron).
 */
#[ORM\Entity(repositoryClass: CrmHealthSnapshotRepository::class)]
#[ORM\Table(name: 'crm_health_snapshot')]
#[ORM\Index(name: 'IDX_CRM_SNAP_USER_DATE', columns: ['utilisateur_id', 'captured_at'])]
#[ORM\HasLifecycleCallbacks]
class CrmHealthSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column]
    private int $score = 0;

    #[ORM\Column(length: 10)]
    private string $couleur = 'rouge';

    /** Détail par critère au moment du calcul. @var array<int, array> */
    #[ORM\Column(type: 'json')]
    private array $details = [];

    #[ORM\Column]
    private ?\DateTimeImmutable $capturedAt = null;

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

    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): static
    {
        $this->score = $score;

        return $this;
    }

    public function getCouleur(): string
    {
        return $this->couleur;
    }

    public function setCouleur(string $couleur): static
    {
        $this->couleur = $couleur;

        return $this;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function setDetails(array $details): static
    {
        $this->details = $details;

        return $this;
    }

    public function getCapturedAt(): ?\DateTimeImmutable
    {
        return $this->capturedAt;
    }

    public function setCapturedAt(?\DateTimeImmutable $capturedAt): static
    {
        $this->capturedAt = $capturedAt;

        return $this;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->capturedAt ??= new \DateTimeImmutable();
    }
}
