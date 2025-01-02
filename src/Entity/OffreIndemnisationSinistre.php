<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use App\Repository\OffreIndemnisationSinistreRepository;

#[ORM\Entity(repositoryClass: OffreIndemnisationSinistreRepository::class)]
class OffreIndemnisationSinistre
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nom = null;

    #[ORM\Column(nullable: true)]
    private ?float $franchiseAppliquee = null;

    #[ORM\Column]
    private ?float $montantPayable = null;

    #[ORM\Column(length: 255)]
    private ?string $beneficiaire = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referenceBancaire = null;

    /**
     * @var Collection<int, Paiement>
     */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'offreIndemnisationSinistre', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $paiements;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'offreIndemnisationSinistre', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    #[ORM\ManyToOne(inversedBy: 'offreIndemnisationSinistres')]
    private ?NotificationSinistre $notificationSinistre = null;

    /**
     * @var Collection<int, Tache>
     */
    #[ORM\OneToMany(targetEntity: Tache::class, mappedBy: 'offreIndemnisationSinistre', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $taches;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->paiements = new ArrayCollection();
        $this->taches = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    // public function getCreatedAt(): ?\DateTimeImmutable
    // {
    //     return $this->createdAt;
    // }

    // public function setCreatedAt(\DateTimeImmutable $createdAt): static
    // {
    //     $this->createdAt = $createdAt;

    //     return $this;
    // }

    // public function getNotification(): ?NotificationSinistre
    // {
    //     return $this->notification;
    // }

    // public function setNotification(?NotificationSinistre $notification): static
    // {
    //     $this->notification = $notification;

    //     return $this;
    // }

    public function getFranchiseAppliquee(): ?float
    {
        return $this->franchiseAppliquee;
    }

    public function setFranchiseAppliquee(?float $franchiseAppliquee): static
    {
        $this->franchiseAppliquee = $franchiseAppliquee;

        return $this;
    }

    public function getMontantPayable(): ?float
    {
        return $this->montantPayable;
    }

    public function setMontantPayable(float $montantPayable): static
    {
        $this->montantPayable = $montantPayable;

        return $this;
    }

    // public function getUpdatedAt(): ?\DateTimeImmutable
    // {
    //     return $this->updatedAt;
    // }

    // public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    // {
    //     $this->updatedAt = $updatedAt;

    //     return $this;
    // }

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
            $document->setOffreIndemnisationSinistre($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getOffreIndemnisationSinistre() === $this) {
                $document->setOffreIndemnisationSinistre(null);
            }
        }

        return $this;
    }

    public function getBeneficiaire(): ?string
    {
        return $this->beneficiaire;
    }

    public function setBeneficiaire(string $beneficiaire): static
    {
        $this->beneficiaire = $beneficiaire;

        return $this;
    }

    public function getReferenceBancaire(): ?string
    {
        return $this->referenceBancaire;
    }

    public function setReferenceBancaire(?string $referenceBancaire): static
    {
        $this->referenceBancaire = $referenceBancaire;

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
            $paiement->setOffreIndemnisationSinistre($this);
        }

        return $this;
    }

    public function removePaiement(Paiement $paiement): static
    {
        if ($this->paiements->removeElement($paiement)) {
            // set the owning side to null (unless already changed)
            if ($paiement->getOffreIndemnisationSinistre() === $this) {
                $paiement->setOffreIndemnisationSinistre(null);
            }
        }

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

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $nom;

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
            $tach->setOffreIndemnisationSinistre($this);
        }

        return $this;
    }

    public function removeTach(Tache $tach): static
    {
        if ($this->taches->removeElement($tach)) {
            // set the owning side to null (unless already changed)
            if ($tach->getOffreIndemnisationSinistre() === $this) {
                $tach->setOffreIndemnisationSinistre(null);
            }
        }

        return $this;
    }
}
