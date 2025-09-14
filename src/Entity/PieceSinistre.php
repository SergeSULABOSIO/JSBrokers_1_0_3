<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\PieceSinistreRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: PieceSinistreRepository::class)]
class PieceSinistre implements OwnerAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'pieceSinistres')]
    private ?ModelePieceSinistre $type = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $receivedAt = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $fourniPar = null;

    #[ORM\ManyToOne(inversedBy: 'pieceSinistres')]
    #[Groups(['list:read'])]
    private ?Invite $invite = null;

    #[ORM\ManyToOne(inversedBy: 'pieces')]
    private ?NotificationSinistre $notificationSinistre = null;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'pieceSinistre')]
    #[Groups(['list:read'])]
    private Collection $documents;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getType(): ?ModelePieceSinistre
    {
        return $this->type;
    }

    public function setType(?ModelePieceSinistre $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getReceivedAt(): ?\DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(\DateTimeImmutable $receivedAt): static
    {
        $this->receivedAt = $receivedAt;

        return $this;
    }

    public function getFourniPar(): ?string
    {
        return $this->fourniPar;
    }

    public function setFourniPar(string $fourniPar): static
    {
        $this->fourniPar = $fourniPar;

        return $this;
    }

    public function getInvite(): ?Invite
    {
        return $this->invite;
    }

    public function setInvite(?Invite $invite): static
    {
        $this->invite = $invite;

        return $this;
    }

    public function getNotificationSinistre(): ?NotificationSinistre
    {
        return $this->notificationSinistre;
    }

    public function setNotificationSinistre(?NotificationSinistre $notificationSinistre): static
    {
        $this->notificationSinistre = $notificationSinistre;

        return $this;
    }

    /**
     * @return Collection<int, Document>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setPieceSinistre($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getPieceSinistre() === $this) {
                $document->setPieceSinistre(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        // return $this->description;
        // On utilise l'opérateur "null coalescing" (??) pour renvoyer
        // la description si elle existe, ou une chaîne vide ('') si elle est null.
        // Cela garantit que la fonction renvoie TOUJOURS une chaîne de caractères.
        return $this->description ?? '';
    }
}
