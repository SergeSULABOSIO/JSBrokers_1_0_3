<?php

namespace App\Entity;

use App\Repository\AssistantMessageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Message d'une conversation avec l'assistant IA (question de l'invité ou
 * réponse de l'assistant). `meta` trace le comportement du moteur pour audit
 * et tests : {"tool": "compter_entites"} ou {"refus": true} par exemple.
 */
#[ORM\Entity(repositoryClass: AssistantMessageRepository::class)]
#[ORM\HasLifecycleCallbacks]
class AssistantMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AssistantConversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AssistantConversation $conversation = null;

    #[ORM\Column(length: 12)]
    private ?string $role = null;

    #[ORM\Column(type: 'text')]
    private ?string $contenu = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meta = null;

    /**
     * Instantané IMMUABLE des objets du contexte au moment de l'envoi (messages
     * utilisateur uniquement) : [{type, id, nom}]. Le message « transporte » ses
     * objets — la liste courante de la conversation évolue, ce cliché jamais.
     * Null = aucun objet attaché à l'envoi (pas d'agrafe).
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $contexteObjets = null;

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

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;
        return $this;
    }

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): self
    {
        $this->contenu = $contenu;
        return $this;
    }

    public function getMeta(): ?array
    {
        return $this->meta;
    }

    public function setMeta(?array $meta): self
    {
        $this->meta = $meta;
        return $this;
    }

    /** @return array<int, array{type: string, id: int, nom: string}>|null */
    public function getContexteObjets(): ?array
    {
        return $this->contexteObjets;
    }

    public function setContexteObjets(?array $contexteObjets): self
    {
        $this->contexteObjets = $contexteObjets !== [] ? $contexteObjets : null;
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
