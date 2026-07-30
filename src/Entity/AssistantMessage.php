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

    /**
     * Instantané IMMUABLE des fichiers attachés à l'envoi (messages utilisateur
     * uniquement) : [{id, nom, type, taille}]. Même logique que contexteObjets —
     * le message « transporte » les pièces jointes telles qu'elles étaient à
     * l'envoi (agrafe dédiée sur la bulle). Null = aucun fichier attaché.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fichiersJoints = null;

    /**
     * Message CITÉ par celui-ci (« Répondre » du menu de bulle, façon WhatsApp),
     * toujours dans la MÊME conversation — garanti par le contrôleur, qui
     * résout l'id via AssistantMessageRepository::findDansConversation().
     *
     * Relation et NON instantané JSON, contrairement à contexteObjets /
     * fichiersJoints : ces deux-là figent une liste COURANTE qui évolue, alors
     * que le contenu d'un message n'est jamais édité — il n'y a donc rien à
     * figer, et l'id resterait de toute façon nécessaire pour faire défiler le
     * fil jusqu'à la bulle citée.
     *
     * Pas d'inversedBy : personne n'a besoin de savoir « qui m'a cité ».
     * NULL = message ordinaire.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AssistantMessage $repondA = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    /** Longueur de l'extrait cité (bulle, composer, prompt) — SOURCE UNIQUE. */
    public const EXTRAIT_CITATION_MAX = 240;

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

    /** @return array<int, array{id: int, nom: string, type: string, taille: int}>|null */
    public function getFichiersJoints(): ?array
    {
        return $this->fichiersJoints;
    }

    public function setFichiersJoints(?array $fichiersJoints): self
    {
        $this->fichiersJoints = $fichiersJoints !== [] ? $fichiersJoints : null;
        return $this;
    }

    public function getRepondA(): ?AssistantMessage
    {
        return $this->repondA;
    }

    public function setRepondA(?AssistantMessage $repondA): self
    {
        $this->repondA = $repondA;
        return $this;
    }

    /**
     * Extrait normalisé du message, SOURCE UNIQUE de toute citation : bulle
     * (Twig), bandeau du composer, marqueur injecté au prompt et réponse JSON.
     * Les sauts de ligne sont aplatis — un marqueur de prompt multiligne
     * casserait la lisibilité du tour de parole.
     */
    public function extraitCitation(): string
    {
        $plat = trim((string) preg_replace('/\s+/u', ' ', (string) $this->contenu));

        return mb_strlen($plat) > self::EXTRAIT_CITATION_MAX
            ? mb_substr($plat, 0, self::EXTRAIT_CITATION_MAX) . '…'
            : $plat;
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
