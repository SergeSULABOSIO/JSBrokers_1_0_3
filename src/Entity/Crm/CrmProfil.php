<?php

namespace App\Entity\Crm;

use App\Entity\Utilisateur;
use App\Repository\Crm\CrmProfilRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @file Profil CRM d'un client (utilisateur propriétaire payant ou prospect).
 * @description Étend l'utilisateur côté équipe JS Brokers UNIQUEMENT (invisible
 * du client) : étape du pipeline commercial, score de santé, agent référent,
 * dates de relance. Données plateforme (pas de scope entreprise/invité). Le
 * profil est créé/synchronisé automatiquement (à la connexion + à l'affichage de
 * la fiche) : le commercial ne saisit jamais une information déjà connue du SaaS.
 */
#[ORM\Entity(repositoryClass: CrmProfilRepository::class)]
#[ORM\Table(name: 'crm_profil')]
#[ORM\HasLifecycleCallbacks]
class CrmProfil
{
    /** Utilisateur (client) décrit par ce profil — relation 1‑1. */
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $utilisateur = null;

    /** Étape courante du pipeline (cf. App\Crm\CrmPipelineService::STAGES). */
    #[ORM\Column(length: 30)]
    private string $etapePipeline = 'prospect';

    /**
     * Vrai quand un commercial a forcé manuellement une étape relationnelle
     * (démo, qualification…). La dérivation automatique n'écrase alors l'étape
     * que pour avancer vers un jalon dur (1ᵉʳ achat) ou pour signaler un churn.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $etapeManuelleForcee = false;

    /** Agent JS Brokers responsable du compte (ROLE_ADMIN). */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $agentReferent = null;

    /** Score de santé 0‑100 (dernier calcul). */
    #[ORM\Column(options: ['default' => 0])]
    private int $scoreSante = 0;

    /** Couleur associée au score (vert / jaune / orange / rouge). */
    #[ORM\Column(length: 10, options: ['default' => 'rouge'])]
    private string $scoreCouleur = 'rouge';

    /** Indicateur de risque de churn (dérivé du score / de l'inactivité). */
    #[ORM\Column(options: ['default' => false])]
    private bool $risqueChurn = false;

    /** Étiquettes libres (segmentation marketing). @var string[] */
    #[ORM\Column(type: 'json')]
    private array $tags = [];

    /** Origine du compte (acquisition) : vitrine, parrainage, import… */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $source = null;

    /** Notes internes libres de l'équipe commerciale. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /** Date du dernier contact commercial enregistré (interaction). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dernierContactAt = null;

    /** Date de la prochaine action prévue (relance) — alimente « à relancer ». */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $prochaineActionAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(?Utilisateur $utilisateur = null)
    {
        $this->utilisateur = $utilisateur;
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

    public function getEtapePipeline(): string
    {
        return $this->etapePipeline;
    }

    public function setEtapePipeline(string $etapePipeline): static
    {
        $this->etapePipeline = $etapePipeline;

        return $this;
    }

    public function isEtapeManuelleForcee(): bool
    {
        return $this->etapeManuelleForcee;
    }

    public function setEtapeManuelleForcee(bool $etapeManuelleForcee): static
    {
        $this->etapeManuelleForcee = $etapeManuelleForcee;

        return $this;
    }

    public function getAgentReferent(): ?Utilisateur
    {
        return $this->agentReferent;
    }

    public function setAgentReferent(?Utilisateur $agentReferent): static
    {
        $this->agentReferent = $agentReferent;

        return $this;
    }

    public function getScoreSante(): int
    {
        return $this->scoreSante;
    }

    public function setScoreSante(int $scoreSante): static
    {
        $this->scoreSante = max(0, min(100, $scoreSante));

        return $this;
    }

    public function getScoreCouleur(): string
    {
        return $this->scoreCouleur;
    }

    public function setScoreCouleur(string $scoreCouleur): static
    {
        $this->scoreCouleur = $scoreCouleur;

        return $this;
    }

    public function isRisqueChurn(): bool
    {
        return $this->risqueChurn;
    }

    public function setRisqueChurn(bool $risqueChurn): static
    {
        $this->risqueChurn = $risqueChurn;

        return $this;
    }

    /** @return string[] */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @param string[] $tags */
    public function setTags(array $tags): static
    {
        $this->tags = array_values(array_unique($tags));

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getDernierContactAt(): ?\DateTimeImmutable
    {
        return $this->dernierContactAt;
    }

    public function setDernierContactAt(?\DateTimeImmutable $dernierContactAt): static
    {
        $this->dernierContactAt = $dernierContactAt;

        return $this;
    }

    public function getProchaineActionAt(): ?\DateTimeImmutable
    {
        return $this->prochaineActionAt;
    }

    public function setProchaineActionAt(?\DateTimeImmutable $prochaineActionAt): static
    {
        $this->prochaineActionAt = $prochaineActionAt;

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

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
