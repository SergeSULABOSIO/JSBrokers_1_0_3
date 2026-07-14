<?php

namespace App\Entity;

use App\Repository\AssistantConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fil de conversation entre un invité et l'assistant IA de l'entreprise.
 * L'historique est PAR INVITÉ (confidentialité entre collègues) : un invité ne
 * voit et ne manipule que ses propres conversations. Le titre est dérivé du
 * premier message envoyé.
 */
#[ORM\Entity(repositoryClass: AssistantConversationRepository::class)]
#[ORM\HasLifecycleCallbacks]
class AssistantConversation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entreprise $entreprise = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Invite $invite = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $titre = null;

    /** @var Collection<int, AssistantMessage> */
    #[ORM\OneToMany(targetEntity: AssistantMessage::class, mappedBy: 'conversation', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $messages;

    /** @var Collection<int, AssistantConversationContexte> */
    #[ORM\OneToMany(targetEntity: AssistantConversationContexte::class, mappedBy: 'conversation', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $contextes;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
        $this->contextes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): self
    {
        $this->entreprise = $entreprise;
        return $this;
    }

    public function getInvite(): ?Invite
    {
        return $this->invite;
    }

    public function setInvite(?Invite $invite): self
    {
        $this->invite = $invite;
        return $this;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): self
    {
        $this->titre = $titre;
        return $this;
    }

    /** @return Collection<int, AssistantMessage> */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(AssistantMessage $message): self
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setConversation($this);
        }
        return $this;
    }

    public function removeMessage(AssistantMessage $message): self
    {
        if ($this->messages->removeElement($message) && $message->getConversation() === $this) {
            $message->setConversation(null);
        }
        return $this;
    }

    /** @return Collection<int, AssistantConversationContexte> */
    public function getContextes(): Collection
    {
        return $this->contextes;
    }

    public function addContexte(AssistantConversationContexte $contexte): self
    {
        if (!$this->contextes->contains($contexte)) {
            $this->contextes->add($contexte);
            $contexte->setConversation($this);
        }
        return $this;
    }

    public function removeContexte(AssistantConversationContexte $contexte): self
    {
        if ($this->contextes->removeElement($contexte) && $contexte->getConversation() === $this) {
            $contexte->setConversation(null);
        }
        return $this;
    }

    /** L'objet (type + id) est-il déjà attaché à cette conversation ? */
    public function hasContexte(string $entityType, int $entityId): bool
    {
        foreach ($this->contextes as $contexte) {
            if ($contexte->getEntityType() === $entityType && $contexte->getEntityId() === $entityId) {
                return true;
            }
        }
        return false;
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
        $this->createdAt = new \DateTimeImmutable('now');
        $this->updatedAt = new \DateTimeImmutable('now');
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now');
    }
}
