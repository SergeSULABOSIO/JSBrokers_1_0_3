<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Objet du workspace attaché par l'utilisateur au contexte d'une conversation
 * avec l'assistant IA (Client, Assureur, Note…). Le couple (entityType,
 * entityId) référence l'enregistrement ; le label est un instantané du libellé
 * au moment de l'attache, pour afficher la puce même si l'objet est supprimé
 * ensuite. La fiche est re-lue et re-validée (droits + scoping entreprise) à
 * chaque envoi de message — jamais de confiance au seul rattachement.
 */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_assistant_ctx_objet', columns: ['conversation_id', 'entity_type', 'entity_id'])]
class AssistantConversationContexte
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'contextes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AssistantConversation $conversation = null;

    /** Nom court de l'entité (whitelist de la carte de permissions, ex. "Client"). */
    #[ORM\Column(length: 80)]
    private ?string $entityType = null;

    #[ORM\Column]
    private ?int $entityId = null;

    /** Instantané du libellé de l'objet au moment de l'attache. */
    #[ORM\Column(length: 160)]
    private ?string $label = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): ?AssistantConversation
    {
        return $this->conversation;
    }

    public function setConversation(?AssistantConversation $conversation): self
    {
        $this->conversation = $conversation;
        return $this;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): self
    {
        $this->entityType = $entityType;
        return $this;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(int $entityId): self
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable('now');
    }
}
