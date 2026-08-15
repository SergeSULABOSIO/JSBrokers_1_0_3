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

    /** @var Collection<int, AssistantConversationFichier> */
    #[ORM\OneToMany(targetEntity: AssistantConversationFichier::class, mappedBy: 'conversation', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $fichiers;

    /**
     * Documents PRODUITS par Ket dans ce fil.
     *
     * `cascade: ['remove']` en plus de l'orphanRemoval, à la différence des pièces
     * jointes : la suppression doit passer par l'ORM pour que Vich efface aussi le
     * binaire. Un `ON DELETE CASCADE` seul court-circuite les événements Doctrine
     * et laisserait des fichiers orphelins sur le disque.
     *
     * @var Collection<int, AssistantDocument>
     */
    #[ORM\OneToMany(targetEntity: AssistantDocument::class, mappedBy: 'conversation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $documentsGeneres;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Depuis quand un worker draine cette conversation — le VERROU qui garantit
     * qu'un seul le fait à la fois. Voir VerrouDeConversation : la colonne n'est
     * jamais lue ni écrite par l'ORM, uniquement par un UPDATE conditionnel
     * atomique. Elle est déclarée ici pour que le schéma la connaisse, et pour
     * qu'un `doctrine:migrations:diff` ne propose pas de la supprimer.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $traitementDepuis = null;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
        $this->contextes = new ArrayCollection();
        $this->fichiers = new ArrayCollection();
        $this->documentsGeneres = new ArrayCollection();
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

    /**
     * Ce qu'on AFFICHE pour désigner cette conversation. Source unique : onglet
     * de la colonne 4, liste de la colonne 3, charges utiles JSON.
     *
     * POURQUOI UN LIBELLÉ DÉRIVÉ PLUTÔT QU'UN TITRE ÉCRIT EN BASE. Le titre
     * était auparavant fabriqué au premier message, en tronquant celui-ci à
     * quatre-vingts caractères. C'était long, c'était laid dans un onglet — la
     * barre s'étirait sur une phrase entière — et surtout c'était FIGÉ : le
     * hasard de la première phrase collait à la conversation pour toujours.
     *
     * Une conversation non renommée s'appelle donc « CONV#135 ». Court, stable,
     * et sans ambiguïté quand plusieurs onglets sont ouverts. `titre` reste NUL
     * en base tant que l'utilisateur n'a rien choisi : rien à migrer, et le jour
     * où il renomme, c'est son texte qui prime.
     */
    public function libelle(): string
    {
        return $this->titre ?? 'CONV#' . $this->id;
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

    /**
     * Le DERNIER message de l'assistant dans ce fil, ou null s'il n'y en a aucun.
     *
     * Vit ici parce que plusieurs règles de conduite s'y accrochent — l'aiguillage
     * de trousse (le tour précédent a-t-il écrit ? proposé d'écrire ?) et la garde
     * anti-boucle des clarifications — et que la même boucle recopiée dans chacune
     * finirait par diverger. La collection est déjà triée par identifiant croissant.
     */
    public function dernierMessageAssistant(): ?AssistantMessage
    {
        $dernier = null;
        foreach ($this->messages as $message) {
            if ($message->getRole() === AssistantMessage::ROLE_ASSISTANT) {
                $dernier = $message;
            }
        }

        return $dernier;
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

    /** @return Collection<int, AssistantDocument> */
    public function getDocumentsGeneres(): Collection
    {
        return $this->documentsGeneres;
    }

    public function addDocumentGenere(AssistantDocument $document): self
    {
        if (!$this->documentsGeneres->contains($document)) {
            $this->documentsGeneres->add($document);
            $document->setConversation($this);
        }

        return $this;
    }

    public function removeDocumentGenere(AssistantDocument $document): self
    {
        if ($this->documentsGeneres->removeElement($document) && $document->getConversation() === $this) {
            $document->setConversation(null);
        }

        return $this;
    }

    /** @return Collection<int, AssistantConversationFichier> */
    public function getFichiers(): Collection
    {
        return $this->fichiers;
    }

    public function addFichier(AssistantConversationFichier $fichier): self
    {
        if (!$this->fichiers->contains($fichier)) {
            $this->fichiers->add($fichier);
            $fichier->setConversation($this);
        }
        return $this;
    }

    public function removeFichier(AssistantConversationFichier $fichier): self
    {
        if ($this->fichiers->removeElement($fichier) && $fichier->getConversation() === $this) {
            $fichier->setConversation(null);
        }
        return $this;
    }

    /** Nombre de fichiers actuellement attachés à cette conversation. */
    public function nbFichiers(): int
    {
        return $this->fichiers->count();
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
