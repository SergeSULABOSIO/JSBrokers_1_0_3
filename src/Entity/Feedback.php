<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\FeedbackRepository;
use App\Entity\Traits\TimestampableTrait;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: FeedbackRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Feedback implements OwnerAwareInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $nextActionAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $nextAction = null;

    #[ORM\ManyToOne(inversedBy: 'feedbacks')]
    private ?Tache $tache = null;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'feedback')]
    private Collection $documents;

    #[ORM\Column]
    private ?int $type = null;
    public const TYPE_PHYSICAL_MEETING = 0;
    public const TYPE_CALL = 1;
    public const TYPE_EMAIL = 2;
    public const TYPE_SMS = 3;
    public const TYPE_UNDEFINED = 4;
    
    #[ORM\ManyToOne(inversedBy: 'feedback')]
    private ?Invite $invite = null;

    #[ORM\Column(nullable: true)]
    private ?bool $hasNextAction = null;


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

    public function getInvite(): ?Invite
    {
        return $this->invite;
    }

    public function setInvite(?Invite $invite): static
    {
        $this->invite = $invite;

        return $this;
    }

    public function getNextActionAt(): ?\DateTimeImmutable
    {
        return $this->nextActionAt;
    }

    public function setNextActionAt(?\DateTimeImmutable $nextActionAt): static
    {
        $this->nextActionAt = $nextActionAt;

        return $this;
    }

    public function getNextAction(): ?string
    {
        return $this->nextAction;
    }

    public function setNextAction(?string $nextAction): static
    {
        $this->nextAction = $nextAction;

        return $this;
    }

    public function getTache(): ?Tache
    {
        return $this->tache;
    }

    public function setTache(?Tache $tache): static
    {
        $this->tache = $tache;

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
            $document->setFeedback($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getFeedback() === $this) {
                $document->setFeedback(null);
            }
        }

        return $this;
    }

    public function getType(): ?int
    {
        return $this->type;
    }

    public function setType(int $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function hasNextAction(): ?bool
    {
        return $this->hasNextAction;
    }

    public function setHasNextAction(?bool $hasNextAction): static
    {
        $this->hasNextAction = $hasNextAction;

        return $this;
    }

    public function __toString(): string
    {
        return $this->description ?? 'Nouveau feedback';
    }
}
