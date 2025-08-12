<?php

namespace App\Entity;

use Symfony\Component\Serializer\Annotation\Groups;
use App\Repository\NotificationSinistreRepository;
use DateTime;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationSinistreRepository::class)]
class NotificationSinistre
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])] // [MODIFICATION 2] Autoriser la sérialisation de l'ID
    private ?int $id = null;
    
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $referencePolice = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $referenceSinistre = null;
    
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $descriptionDeFait = null;
    
    #[ORM\ManyToOne(inversedBy: 'notificationSinistres')]
    #[Groups(['list:read'])]
    private ?Client $assure = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $occuredAt = null;

    #[ORM\Column(length: 255, nullable: true, options: ["default" => "Non défini."])]
    #[Groups(['list:read'])]
    private ?string $lieu = null;

    #[ORM\ManyToOne(inversedBy: 'notificationSinistres')]
    #[Groups(['list:read'])]
    private ?Risque $risque = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'notificationSinistres')]
    #[Groups(['list:read'])]
    private ?Invite $invite = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $dommage = null;

    /**
     * @var Collection<int, OffreIndemnisationSinistre>
     */
    #[ORM\OneToMany(targetEntity: OffreIndemnisationSinistre::class, mappedBy: 'notificationSinistre', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['list:read'])]
    private Collection $offreIndemnisationSinistres;
    
    /**
     * @var Collection<int, PieceSinistre>
     */
    #[ORM\OneToMany(targetEntity: PieceSinistre::class, mappedBy: 'notificationSinistre', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['list:read'])]
    private Collection $pieces;

    /**
     * @var Collection<int, Contact>
     */
    #[ORM\OneToMany(targetEntity: Contact::class, mappedBy: 'notificationSinistre', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['list:read'])]
    private Collection $contacts;

    /**
     * @var Collection<int, Tache>
     */
    #[ORM\OneToMany(targetEntity: Tache::class, mappedBy: 'notificationSinistre', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['list:read'])]
    private Collection $taches;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $evaluationChiffree = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $notifiedAt = null;

    #[ORM\ManyToOne(inversedBy: 'notificationSinistres')]
    #[Groups(['list:read'])]
    private ?Assureur $assureur = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $descriptionVictimes = null;


    //Attributs calculés
    #[Groups(['list:read'])]
    public ?int $attributTest;

    #[Groups(['list:read'])]
    public ?DateTime $dateDernierReglement;

    #[Groups(['list:read'])]
    public ?int $dureeReglement;

    #[Groups(['list:read'])]
    public ?array $statusDocumentsAttendus;

    #[Groups(['list:read'])]
    public ?float $compensationFranchise;

    #[Groups(['list:read'])]
    public ?float $compensationSoldeAverser;

    #[Groups(['list:read'])]
    public ?float $compensationVersee;

    #[Groups(['list:read'])]
    public ?float $compensation;

    public function __construct()
    {
        $this->pieces = new ArrayCollection();
        $this->contacts = new ArrayCollection();
        $this->offreIndemnisationSinistres = new ArrayCollection();
        $this->taches = new ArrayCollection();
    }

    


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDescriptionDeFait(): ?string
    {
        return $this->descriptionDeFait;
    }

    public function setDescriptionDeFait(string $descriptionDeFait): static
    {
        $this->descriptionDeFait = $descriptionDeFait;

        return $this;
    }

    public function getOccuredAt(): ?\DateTimeImmutable
    {
        return $this->occuredAt;
    }

    public function setOccuredAt(\DateTimeImmutable $occuredAt): static
    {
        $this->occuredAt = $occuredAt;

        return $this;
    }

    public function getLieu(): ?string
    {
        return $this->lieu;
    }

    public function setLieu(string $lieu): static
    {
        $this->lieu = $lieu;

        return $this;
    }

    /**
     * @return Collection<int, PieceSinistre>
     */
    public function getPieces(): Collection
    {
        return $this->pieces;
    }

    public function addPiece(PieceSinistre $piece): static
    {
        if (!$this->pieces->contains($piece)) {
            $this->pieces->add($piece);
            $piece->setNotificationSinistre($this);
        }

        return $this;
    }

    public function removePiece(PieceSinistre $piece): static
    {
        if ($this->pieces->removeElement($piece)) {
            // set the owning side to null (unless already changed)
            if ($piece->getNotificationSinistre() === $this) {
                $piece->setNotificationSinistre(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Contact>
     */
    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    public function addContact(Contact $contact): static
    {
        if (!$this->contacts->contains($contact)) {
            $this->contacts->add($contact);
            $contact->setNotificationSinistre($this);
        }

        return $this;
    }

    public function removeContact(Contact $contact): static
    {
        if ($this->contacts->removeElement($contact)) {
            // set the owning side to null (unless already changed)
            if ($contact->getNotificationSinistre() === $this) {
                $contact->setNotificationSinistre(null);
            }
        }

        return $this;
    }

    public function getAssure(): ?Client
    {
        return $this->assure;
    }

    public function setAssure(?Client $assure): static
    {
        $this->assure = $assure;

        return $this;
    }

    public function getReferencePolice(): ?string
    {
        return $this->referencePolice;
    }

    public function setReferencePolice(string $referencePolice): static
    {
        $this->referencePolice = $referencePolice;

        return $this;
    }

    public function getReferenceSinistre(): ?string
    {
        return $this->referenceSinistre;
    }

    public function setReferenceSinistre(?string $referenceSinistre): static
    {
        $this->referenceSinistre = $referenceSinistre;

        return $this;
    }

    public function getRisque(): ?Risque
    {
        return $this->risque;
    }

    public function setRisque(?Risque $risque): static
    {
        $this->risque = $risque;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

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

    public function getDommage(): ?float
    {
        return $this->dommage;
    }

    public function setDommage(?float $dommage): static
    {
        $this->dommage = $dommage;

        return $this;
    }

    /**
     * @return Collection<int, OffreIndemnisationSinistre>
     */
    public function getOffreIndemnisationSinistres(): Collection
    {
        return $this->offreIndemnisationSinistres;
    }

    public function addOffreIndemnisationSinistre(OffreIndemnisationSinistre $offreIndemnisationSinistre): static
    {
        if (!$this->offreIndemnisationSinistres->contains($offreIndemnisationSinistre)) {
            $this->offreIndemnisationSinistres->add($offreIndemnisationSinistre);
            $offreIndemnisationSinistre->setNotificationSinistre($this);
        }

        return $this;
    }

    public function removeOffreIndemnisationSinistre(OffreIndemnisationSinistre $offreIndemnisationSinistre): static
    {
        if ($this->offreIndemnisationSinistres->removeElement($offreIndemnisationSinistre)) {
            // set the owning side to null (unless already changed)
            if ($offreIndemnisationSinistre->getNotificationSinistre() === $this) {
                $offreIndemnisationSinistre->setNotificationSinistre(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Tache>
     */
    public function getTaches(): Collection
    {
        return $this->taches;
    }

    public function addTach(Tache $tach): static
    {
        if (!$this->taches->contains($tach)) {
            $this->taches->add($tach);
            $tach->setNotificationSinistre($this);
        }

        return $this;
    }

    public function removeTach(Tache $tach): static
    {
        if ($this->taches->removeElement($tach)) {
            // set the owning side to null (unless already changed)
            if ($tach->getNotificationSinistre() === $this) {
                $tach->setNotificationSinistre(null);
            }
        }

        return $this;
    }

    public function getEvaluationChiffree(): ?float
    {
        return $this->evaluationChiffree;
    }

    public function setEvaluationChiffree(?float $evaluationChiffree): static
    {
        $this->evaluationChiffree = $evaluationChiffree;

        return $this;
    }

    public function getNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    public function setNotifiedAt(?\DateTimeImmutable $notifiedAt): static
    {
        $this->notifiedAt = $notifiedAt;

        return $this;
    }

    public function getAssureur(): ?Assureur
    {
        return $this->assureur;
    }

    public function setAssureur(?Assureur $assureur): static
    {
        $this->assureur = $assureur;

        return $this;
    }

    public function getDescriptionVictimes(): ?string
    {
        return $this->descriptionVictimes;
    }

    public function setDescriptionVictimes(?string $descriptionVictimes): static
    {
        $this->descriptionVictimes = $descriptionVictimes;

        return $this;
    }
}
