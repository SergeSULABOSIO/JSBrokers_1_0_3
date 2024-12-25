<?php

namespace App\Entity;

use App\Repository\InviteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InviteRepository::class)]
class Invite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotBlank()]
    #[ORM\Column(length: 255)]
    private ?string $email = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;


    #[ORM\ManyToOne(inversedBy: 'invites')]
    private ?Utilisateur $utilisateur = null;

    /**
     * @var Collection<int, Piste>
     */
    #[ORM\OneToMany(targetEntity: Piste::class, mappedBy: 'invite')]
    private Collection $pistes;

    /**
     * @var Collection<int, Tache>
     */
    #[ORM\OneToMany(targetEntity: Tache::class, mappedBy: 'invite')]
    private Collection $taches;

    /**
     * @var Collection<int, Feedback>
     */
    #[ORM\OneToMany(targetEntity: Feedback::class, mappedBy: 'invite')]
    private Collection $feedback;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'invite')]
    private Collection $documents;

    /**
     * @var Collection<int, Tranche>
     */
    #[ORM\OneToMany(targetEntity: Tranche::class, mappedBy: 'invite')]
    private Collection $tranches;

    /**
     * @var Collection<int, Avenant>
     */
    #[ORM\OneToMany(targetEntity: Avenant::class, mappedBy: 'invite')]
    private Collection $avenants;

    /**
     * @var Collection<int, Cotation>
     */
    #[ORM\OneToMany(targetEntity: Cotation::class, mappedBy: 'invite')]
    private Collection $cotations;

    /**
     * @var Collection<int, Bordereau>
     */
    #[ORM\OneToMany(targetEntity: Bordereau::class, mappedBy: 'invite')]
    private Collection $bordereaus;

    /**
     * @var Collection<int, FactureCommission>
     */
    #[ORM\OneToMany(targetEntity: FactureCommission::class, mappedBy: 'invite')]
    private Collection $factureCommissions;

    /**
     * @var Collection<int, Paiement>
     */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'invite')]
    private Collection $paiements;

    /**
     * @var Collection<int, PieceSinistre>
     */
    #[ORM\OneToMany(targetEntity: PieceSinistre::class, mappedBy: 'invite')]
    private Collection $pieceSinistres;

    /**
     * @var Collection<int, NotificationSinistre>
     */
    #[ORM\OneToMany(targetEntity: NotificationSinistre::class, mappedBy: 'invite')]
    private Collection $notificationSinistres;

    /**
     * @var Collection<int, OffreIndemnisationSinistre>
     */
    #[ORM\OneToMany(targetEntity: OffreIndemnisationSinistre::class, mappedBy: 'invite')]
    private Collection $offreIndemnisationSinistres;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nom = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'assistants')]
    private ?self $invite = null;

    /**
     * @var Collection<int, self>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'invite')]
    private Collection $assistants;

    #[ORM\ManyToOne(inversedBy: 'invites')]
    private ?Entreprise $entreprise = null;

    public function __construct()
    {
        // $this->entreprises = new ArrayCollection();
        $this->pistes = new ArrayCollection();
        $this->taches = new ArrayCollection();
        $this->feedback = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->tranches = new ArrayCollection();
        $this->avenants = new ArrayCollection();
        $this->cotations = new ArrayCollection();
        $this->bordereaus = new ArrayCollection();
        $this->factureCommissions = new ArrayCollection();
        $this->paiements = new ArrayCollection();
        $this->pieceSinistres = new ArrayCollection();
        $this->notificationSinistres = new ArrayCollection();
        $this->offreIndemnisationSinistres = new ArrayCollection();
        $this->assistants = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

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

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    /**
     * @return Collection<int, Piste>
     */
    public function getPistes(): Collection
    {
        return $this->pistes;
    }

    public function addPiste(Piste $piste): static
    {
        if (!$this->pistes->contains($piste)) {
            $this->pistes->add($piste);
            $piste->setInvite($this);
        }

        return $this;
    }

    public function removePiste(Piste $piste): static
    {
        if ($this->pistes->removeElement($piste)) {
            // set the owning side to null (unless already changed)
            if ($piste->getInvite() === $this) {
                $piste->setInvite(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom . " (" . $this->email . ")";
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
            $tach->setInvite($this);
        }

        return $this;
    }

    public function removeTach(Tache $tach): static
    {
        if ($this->taches->removeElement($tach)) {
            // set the owning side to null (unless already changed)
            if ($tach->getInvite() === $this) {
                $tach->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Feedback>
     */
    public function getFeedback(): Collection
    {
        return $this->feedback;
    }

    public function addFeedback(Feedback $feedback): static
    {
        if (!$this->feedback->contains($feedback)) {
            $this->feedback->add($feedback);
            $feedback->setInvite($this);
        }

        return $this;
    }

    public function removeFeedback(Feedback $feedback): static
    {
        if ($this->feedback->removeElement($feedback)) {
            // set the owning side to null (unless already changed)
            if ($feedback->getInvite() === $this) {
                $feedback->setInvite(null);
            }
        }

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
            $document->setInvite($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getInvite() === $this) {
                $document->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Tranche>
     */
    public function getTranches(): Collection
    {
        return $this->tranches;
    }

    public function addTranch(Tranche $tranch): static
    {
        if (!$this->tranches->contains($tranch)) {
            $this->tranches->add($tranch);
            $tranch->setInvite($this);
        }

        return $this;
    }

    public function removeTranch(Tranche $tranch): static
    {
        if ($this->tranches->removeElement($tranch)) {
            // set the owning side to null (unless already changed)
            if ($tranch->getInvite() === $this) {
                $tranch->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Avenant>
     */
    public function getAvenants(): Collection
    {
        return $this->avenants;
    }

    public function addAvenant(Avenant $avenant): static
    {
        if (!$this->avenants->contains($avenant)) {
            $this->avenants->add($avenant);
            $avenant->setInvite($this);
        }

        return $this;
    }

    public function removeAvenant(Avenant $avenant): static
    {
        if ($this->avenants->removeElement($avenant)) {
            // set the owning side to null (unless already changed)
            if ($avenant->getInvite() === $this) {
                $avenant->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Cotation>
     */
    public function getCotations(): Collection
    {
        return $this->cotations;
    }

    public function addCotation(Cotation $cotation): static
    {
        if (!$this->cotations->contains($cotation)) {
            $this->cotations->add($cotation);
            $cotation->setInvite($this);
        }

        return $this;
    }

    public function removeCotation(Cotation $cotation): static
    {
        if ($this->cotations->removeElement($cotation)) {
            // set the owning side to null (unless already changed)
            if ($cotation->getInvite() === $this) {
                $cotation->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Bordereau>
     */
    public function getBordereaus(): Collection
    {
        return $this->bordereaus;
    }

    public function addBordereau(Bordereau $bordereau): static
    {
        if (!$this->bordereaus->contains($bordereau)) {
            $this->bordereaus->add($bordereau);
            $bordereau->setInvite($this);
        }

        return $this;
    }

    public function removeBordereau(Bordereau $bordereau): static
    {
        if ($this->bordereaus->removeElement($bordereau)) {
            // set the owning side to null (unless already changed)
            if ($bordereau->getInvite() === $this) {
                $bordereau->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FactureCommission>
     */
    public function getFactureCommissions(): Collection
    {
        return $this->factureCommissions;
    }

    public function addFactureCommission(FactureCommission $factureCommission): static
    {
        if (!$this->factureCommissions->contains($factureCommission)) {
            $this->factureCommissions->add($factureCommission);
            $factureCommission->setInvite($this);
        }

        return $this;
    }

    public function removeFactureCommission(FactureCommission $factureCommission): static
    {
        if ($this->factureCommissions->removeElement($factureCommission)) {
            // set the owning side to null (unless already changed)
            if ($factureCommission->getInvite() === $this) {
                $factureCommission->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Paiement>
     */
    public function getPaiements(): Collection
    {
        return $this->paiements;
    }

    public function addPaiement(Paiement $paiement): static
    {
        if (!$this->paiements->contains($paiement)) {
            $this->paiements->add($paiement);
            $paiement->setInvite($this);
        }

        return $this;
    }

    public function removePaiement(Paiement $paiement): static
    {
        if ($this->paiements->removeElement($paiement)) {
            // set the owning side to null (unless already changed)
            if ($paiement->getInvite() === $this) {
                $paiement->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PieceSinistre>
     */
    public function getPieceSinistres(): Collection
    {
        return $this->pieceSinistres;
    }

    public function addPieceSinistre(PieceSinistre $pieceSinistre): static
    {
        if (!$this->pieceSinistres->contains($pieceSinistre)) {
            $this->pieceSinistres->add($pieceSinistre);
            $pieceSinistre->setInvite($this);
        }

        return $this;
    }

    public function removePieceSinistre(PieceSinistre $pieceSinistre): static
    {
        if ($this->pieceSinistres->removeElement($pieceSinistre)) {
            // set the owning side to null (unless already changed)
            if ($pieceSinistre->getInvite() === $this) {
                $pieceSinistre->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, NotificationSinistre>
     */
    public function getNotificationSinistres(): Collection
    {
        return $this->notificationSinistres;
    }

    public function addNotificationSinistre(NotificationSinistre $notificationSinistre): static
    {
        if (!$this->notificationSinistres->contains($notificationSinistre)) {
            $this->notificationSinistres->add($notificationSinistre);
            $notificationSinistre->setInvite($this);
        }

        return $this;
    }

    public function removeNotificationSinistre(NotificationSinistre $notificationSinistre): static
    {
        if ($this->notificationSinistres->removeElement($notificationSinistre)) {
            // set the owning side to null (unless already changed)
            if ($notificationSinistre->getInvite() === $this) {
                $notificationSinistre->setInvite(null);
            }
        }

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
            $offreIndemnisationSinistre->setInvite($this);
        }

        return $this;
    }

    public function removeOffreIndemnisationSinistre(OffreIndemnisationSinistre $offreIndemnisationSinistre): static
    {
        if ($this->offreIndemnisationSinistres->removeElement($offreIndemnisationSinistre)) {
            // set the owning side to null (unless already changed)
            if ($offreIndemnisationSinistre->getInvite() === $this) {
                $offreIndemnisationSinistre->setInvite(null);
            }
        }

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getInvite(): ?self
    {
        return $this->invite;
    }

    public function setInvite(?self $invite): static
    {
        $this->invite = $invite;

        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getAssistants(): Collection
    {
        return $this->assistants;
    }

    public function addAssistant(self $assistant): static
    {
        if (!$this->assistants->contains($assistant)) {
            $this->assistants->add($assistant);
            $assistant->setInvite($this);
        }

        return $this;
    }

    public function removeAssistant(self $assistant): static
    {
        if ($this->assistants->removeElement($assistant)) {
            // set the owning side to null (unless already changed)
            if ($assistant->getInvite() === $this) {
                $assistant->setInvite(null);
            }
        }

        return $this;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): static
    {
        $this->entreprise = $entreprise;

        return $this;
    }
}
