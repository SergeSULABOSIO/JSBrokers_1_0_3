<?php

namespace App\Entity\Crm;

use App\Entity\Utilisateur;
use App\Repository\Crm\CrmTicketFeedbackRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @file Feedback (note interne) d'un collaborateur sur un ticket de support.
 * @description Permet aux agents JS Brokers d'échanger sur un ticket tant qu'il
 * n'est pas clos. On trace l'auteur et l'horodatage de chaque message.
 */
#[ORM\Entity(repositoryClass: CrmTicketFeedbackRepository::class)]
#[ORM\Table(name: 'crm_ticket_feedback')]
#[ORM\HasLifecycleCallbacks]
class CrmTicketFeedback
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CrmTicket::class, inversedBy: 'feedbacks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CrmTicket $ticket = null;

    /** Collaborateur ayant rédigé le feedback (conservé même s'il est supprimé). */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $auteur = null;

    #[ORM\Column(type: 'text')]
    private ?string $contenu = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicket(): ?CrmTicket
    {
        return $this->ticket;
    }

    public function setTicket(?CrmTicket $ticket): static
    {
        $this->ticket = $ticket;

        return $this;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): static
    {
        $this->auteur = $auteur;

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
