<?php

namespace App\Entity\Crm;

use App\Entity\Utilisateur;
use App\Repository\Crm\CrmNotificationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @file Notification interne (in-app) pour l'équipe JS Brokers.
 * @description Comble l'absence de notification persistante (jusqu'ici : toasts
 * + e-mail uniquement). Émise par les automatisations (solde bas, churn, ticket
 * en retard…). `agent` null = diffusion à tous les agents. L'état « lu » est
 * global (outil interne) pour rester simple.
 */
#[ORM\Entity(repositoryClass: CrmNotificationRepository::class)]
#[ORM\Table(name: 'crm_notification')]
#[ORM\HasLifecycleCallbacks]
class CrmNotification
{
    public const NIVEAU_INFO   = 'info';
    public const NIVEAU_ALERTE = 'alerte';
    public const NIVEAU_SUCCES = 'succes';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Agent destinataire ; null = tous les agents. */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Utilisateur $agent = null;

    #[ORM\Column(length: 200)]
    private ?string $titre = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column(length: 10, options: ['default' => 'info'])]
    private string $niveau = self::NIVEAU_INFO;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lien = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $lu = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = $titre;

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

    public function getNiveau(): string
    {
        return $this->niveau;
    }

    public function setNiveau(string $niveau): static
    {
        $this->niveau = $niveau;

        return $this;
    }

    public function getLien(): ?string
    {
        return $this->lien;
    }

    public function setLien(?string $lien): static
    {
        $this->lien = $lien;

        return $this;
    }

    public function isLu(): bool
    {
        return $this->lu;
    }

    public function setLu(bool $lu): static
    {
        $this->lu = $lu;

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
    }
}
