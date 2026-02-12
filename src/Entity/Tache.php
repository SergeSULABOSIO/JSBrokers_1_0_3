<?php

namespace App\Entity;

use Symfony\Component\Serializer\Annotation\Groups;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Traits\TimestampableTrait;
use App\Repository\TacheRepository;
use Doctrine\Common\Collections\{ArrayCollection, Collection};

#[ORM\Entity(repositoryClass: TacheRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Tache
{
    use TimestampableTrait;
    
    //Execution status
    public const EXECUTION_STATUS_STILL_VALID      = 0;
    public const EXECUTION_STATUS_EXPIRED          = 1;
    public const EXECUTION_STATUS_COMPLETED        = 2;


    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'taches')]
    #[Groups(['list:read'])]
    private ?Invite $executor = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $toBeEndedAt = null;

    /**
     * @var Collection<int, Feedback>
     */
    #[ORM\OneToMany(targetEntity: Feedback::class, mappedBy: 'tache', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $feedbacks;

    #[ORM\ManyToOne(inversedBy: 'taches')]
    // #[Groups(['list:read'])]
    private ?Piste $piste = null;

    #[ORM\ManyToOne(inversedBy: 'taches')]
    // #[Groups(['list:read'])]
    private ?Cotation $cotation = null;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'tache', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?bool $closed = null;

    #[ORM\ManyToOne(inversedBy: 'taches')]
    // #[Groups(['list:read'])]
    private ?NotificationSinistre $notificationSinistre = null;

    #[ORM\ManyToOne(inversedBy: 'taches')]
    // #[Groups(['list:read'])]
    private ?OffreIndemnisationSinistre $offreIndemnisationSinistre = null;

    // Attribut calculé pour afficher le contexte dans l'UI.
    #[Groups(['list:read'])]
    public ?string $contexteTache = null;

    public function __construct()
    {
        $this->feedbacks = new ArrayCollection();
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

    public function getExecutor(): ?Invite
    {
        return $this->executor;
    }

    public function setExecutor(?Invite $executor): static
    {
        $this->executor = $executor;

        return $this;
    }

    public function getToBeEndedAt(): ?\DateTimeImmutable
    {
        return $this->toBeEndedAt;
    }

    public function setToBeEndedAt(\DateTimeImmutable $toBeEndedAt): static
    {
        $this->toBeEndedAt = $toBeEndedAt;

        return $this;
    }

    /**
     * @return Collection<int, Feedback>
     */
    public function getFeedbacks(): Collection
    {
        return $this->feedbacks;
    }

    public function addFeedback(Feedback $feedback): static
    {
        if (!$this->feedbacks->contains($feedback)) {
            $this->feedbacks->add($feedback);
            $feedback->setTache($this);
        }

        return $this;
    }

    public function removeFeedback(Feedback $feedback): static
    {
        if ($this->feedbacks->removeElement($feedback)) {
            // set the owning side to null (unless already changed)
            if ($feedback->getTache() === $this) {
                $feedback->setTache(null);
            }
        }

        return $this;
    }

    public function getPiste(): ?Piste
    {
        return $this->piste;
    }

    public function setPiste(?Piste $piste): static
    {
        $this->piste = $piste;

        return $this;
    }

    public function getCotation(): ?Cotation
    {
        return $this->cotation;
    }

    public function setCotation(?Cotation $cotation): static
    {
        $this->cotation = $cotation;

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
            $document->setTache($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getTache() === $this) {
                $document->setTache(null);
            }
        }

        return $this;
    }

    public function isClosed(): ?bool
    {
        return $this->closed;
    }

    public function setClosed(bool $closed): static
    {
        $this->closed = $closed;

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

    public function getOffreIndemnisationSinistre(): ?OffreIndemnisationSinistre
    {
        return $this->offreIndemnisationSinistre;
    }

    public function setOffreIndemnisationSinistre(?OffreIndemnisationSinistre $offreIndemnisationSinistre): static
    {
        $this->offreIndemnisationSinistre = $offreIndemnisationSinistre;

        return $this;
    }
}
