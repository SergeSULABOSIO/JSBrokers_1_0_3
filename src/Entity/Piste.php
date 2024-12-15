<?php

namespace App\Entity;

use App\Repository\PisteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PisteRepository::class)]
class Piste
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referencePolice = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;
    
    #[ORM\ManyToOne(inversedBy: 'pistes')]
    private ?Risque $risque = null;

    #[ORM\ManyToOne(inversedBy: 'pistes')]
    private ?Client $client = null;

    #[ORM\ManyToOne(inversedBy: 'pistes')]
    private ?Invite $invite = null;

    #[ORM\Column(nullable: true)]
    private ?float $primePotentielle = null;

    #[ORM\Column(nullable: true)]
    private ?float $commissionPotentielle = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, Tache>
     */
    #[ORM\OneToMany(targetEntity: Tache::class, mappedBy: 'piste')]
    private Collection $taches;

    #[ORM\Column]
    private ?int $typeAvenant = null;

    #[ORM\Column(length: 255)]
    private ?string $descriptionDuRisque = null;

    /**
     * @var Collection<int, Cotation>
     */
    #[ORM\OneToMany(targetEntity: Cotation::class, mappedBy: 'piste')]
    private Collection $cotations;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'piste')]
    private Collection $documents;

    public function __construct()
    {
        $this->taches = new ArrayCollection();
        $this->cotations = new ArrayCollection();
        $this->documents = new ArrayCollection();
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getPrimePotentielle(): ?float
    {
        return $this->primePotentielle;
    }

    public function setPrimePotentielle(?float $primePotentielle): static
    {
        $this->primePotentielle = $primePotentielle;

        return $this;
    }

    public function getCommissionPotentielle(): ?float
    {
        return $this->commissionPotentielle;
    }

    public function setCommissionPotentielle(?float $commissionPotentielle): static
    {
        $this->commissionPotentielle = $commissionPotentielle;

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

    public function __toString(): string
    {
        return $this->nom;
    }

    public function getReferencePolice(): ?string
    {
        return $this->referencePolice;
    }

    public function setReferencePolice(?string $referencePolice): static
    {
        $this->referencePolice = $referencePolice;

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

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
            $tach->setPiste($this);
        }

        return $this;
    }

    public function removeTach(Tache $tach): static
    {
        if ($this->taches->removeElement($tach)) {
            // set the owning side to null (unless already changed)
            if ($tach->getPiste() === $this) {
                $tach->setPiste(null);
            }
        }

        return $this;
    }

    public function getTypeAvenant(): ?int
    {
        return $this->typeAvenant;
    }

    public function setTypeAvenant(int $typeAvenant): static
    {
        $this->typeAvenant = $typeAvenant;

        return $this;
    }

    public function getDescriptionDuRisque(): ?string
    {
        return $this->descriptionDuRisque;
    }

    public function setDescriptionDuRisque(string $descriptionDuRisque): static
    {
        $this->descriptionDuRisque = $descriptionDuRisque;

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
            $cotation->setPiste($this);
        }

        return $this;
    }

    public function removeCotation(Cotation $cotation): static
    {
        if ($this->cotations->removeElement($cotation)) {
            // set the owning side to null (unless already changed)
            if ($cotation->getPiste() === $this) {
                $cotation->setPiste(null);
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
            $document->setPiste($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getPiste() === $this) {
                $document->setPiste(null);
            }
        }

        return $this;
    }
}
