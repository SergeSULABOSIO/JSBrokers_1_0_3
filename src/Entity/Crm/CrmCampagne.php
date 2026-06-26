<?php

namespace App\Entity\Crm;

use App\Repository\Crm\CrmCampagneRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @file Campagne marketing ciblée (équipe JS Brokers).
 * @description Onboarding, recharge, réactivation, upsell. Le segment est défini
 * par des règles (étapes de pipeline / couleurs de santé) évaluées à l'envoi. Les
 * e-mails partent via CorporateMailer ; les cibles et conversions sont tracées.
 */
#[ORM\Entity(repositoryClass: CrmCampagneRepository::class)]
#[ORM\Table(name: 'crm_campagne')]
#[ORM\HasLifecycleCallbacks]
class CrmCampagne
{
    public const TYPE_ONBOARDING   = 'onboarding';
    public const TYPE_RECHARGE     = 'recharge';
    public const TYPE_REACTIVATION = 'reactivation';
    public const TYPE_UPSELL       = 'upsell';

    public const TYPES = [
        self::TYPE_ONBOARDING   => 'Onboarding',
        self::TYPE_RECHARGE     => 'Recharge',
        self::TYPE_REACTIVATION => 'Réactivation',
        self::TYPE_UPSELL       => 'Upsell',
    ];

    public const STATUT_BROUILLON = 'brouillon';
    public const STATUT_ENVOYEE   = 'envoyee';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private ?string $nom = null;

    #[ORM\Column(length: 20)]
    private string $type = self::TYPE_ONBOARDING;

    #[ORM\Column(length: 12, options: ['default' => 'brouillon'])]
    private string $statut = self::STATUT_BROUILLON;

    #[ORM\Column(length: 200)]
    private ?string $objet = null;

    #[ORM\Column(type: 'text')]
    private ?string $message = null;

    /** Règles de segment : { "stages": [...], "couleurs": [...] }. */
    #[ORM\Column(type: 'json')]
    private array $segmentRegles = [];

    #[ORM\Column(options: ['default' => 0])]
    private int $nbCibles = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $nbEnvois = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $nbConversions = 0;

    /** @var Collection<int, CrmCampagneCible> */
    #[ORM\OneToMany(targetEntity: CrmCampagneCible::class, mappedBy: 'campagne', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $cibles;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function __construct()
    {
        $this->cibles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $nom;

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

    public function getTypeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getObjet(): ?string
    {
        return $this->objet;
    }

    public function setObjet(?string $objet): static
    {
        $this->objet = $objet;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getSegmentRegles(): array
    {
        return $this->segmentRegles;
    }

    public function setSegmentRegles(array $segmentRegles): static
    {
        $this->segmentRegles = $segmentRegles;

        return $this;
    }

    public function getNbCibles(): int
    {
        return $this->nbCibles;
    }

    public function setNbCibles(int $nbCibles): static
    {
        $this->nbCibles = $nbCibles;

        return $this;
    }

    public function getNbEnvois(): int
    {
        return $this->nbEnvois;
    }

    public function setNbEnvois(int $nbEnvois): static
    {
        $this->nbEnvois = $nbEnvois;

        return $this;
    }

    public function getNbConversions(): int
    {
        return $this->nbConversions;
    }

    public function setNbConversions(int $nbConversions): static
    {
        $this->nbConversions = $nbConversions;

        return $this;
    }

    /** @return Collection<int, CrmCampagneCible> */
    public function getCibles(): Collection
    {
        return $this->cibles;
    }

    public function addCible(CrmCampagneCible $cible): static
    {
        if (!$this->cibles->contains($cible)) {
            $this->cibles->add($cible);
            $cible->setCampagne($this);
        }

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
