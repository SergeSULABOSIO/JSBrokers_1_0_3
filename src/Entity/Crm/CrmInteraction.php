<?php

namespace App\Entity\Crm;

use App\Entity\Utilisateur;
use App\Repository\Crm\CrmInteractionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @file Interaction commerciale entre un agent JS Brokers et un client.
 * @description Journal des échanges (appel, e-mail, démo, réunion, note) saisis
 * par l'équipe interne. Alimente la timeline de la fiche client. Distinct de
 * App\Entity\Feedback, qui est le CRM du courtier sur SES propres assurés
 * (scopé entreprise) : ici, données plateforme JS Brokers.
 */
#[ORM\Entity(repositoryClass: CrmInteractionRepository::class)]
#[ORM\Table(name: 'crm_interaction')]
#[ORM\HasLifecycleCallbacks]
class CrmInteraction
{
    public const TYPE_APPEL   = 'appel';
    public const TYPE_EMAIL   = 'email';
    public const TYPE_DEMO    = 'demo';
    public const TYPE_REUNION = 'reunion';
    public const TYPE_NOTE    = 'note';

    public const TYPES = [
        self::TYPE_APPEL   => 'Appel',
        self::TYPE_EMAIL   => 'E-mail',
        self::TYPE_DEMO     => 'Démonstration',
        self::TYPE_REUNION => 'Réunion',
        self::TYPE_NOTE    => 'Note',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Client concerné (utilisateur propriétaire). */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $client = null;

    /** Agent JS Brokers auteur de l'interaction. */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $agent = null;

    #[ORM\Column(length: 20)]
    private string $type = self::TYPE_NOTE;

    #[ORM\Column(length: 200)]
    private ?string $sujet = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $contenu = null;

    /** Sens de l'échange : 'out' (sortant) ou 'in' (entrant). */
    #[ORM\Column(length: 3, options: ['default' => 'out'])]
    private string $direction = 'out';

    #[ORM\Column]
    private ?\DateTimeImmutable $occurredAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

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

    public function getSujet(): ?string
    {
        return $this->sujet;
    }

    public function setSujet(?string $sujet): static
    {
        $this->sujet = $sujet;

        return $this;
    }

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

    public function setContenu(?string $contenu): static
    {
        $this->contenu = $contenu;

        return $this;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }

    public function setDirection(string $direction): static
    {
        $this->direction = $direction === 'in' ? 'in' : 'out';

        return $this;
    }

    public function getOccurredAt(): ?\DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(?\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
        $this->occurredAt ??= $this->createdAt;
    }
}
