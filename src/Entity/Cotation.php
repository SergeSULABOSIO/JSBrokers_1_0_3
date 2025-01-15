<?php

namespace App\Entity;

use App\Repository\CotationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CotationRepository::class)]
class Cotation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column]
    private ?int $duree = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'cotations')]
    private ?Assureur $assureur = null;

    /**
     * @var Collection<int, Tache>
     */
    #[ORM\OneToMany(targetEntity: Tache::class, mappedBy: 'cotation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $taches;

    /**
     * @var Collection<int, ChargementPourPrime>
     */
    #[ORM\OneToMany(targetEntity: ChargementPourPrime::class, mappedBy: 'cotation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $chargements;

    /**
     * @var Collection<int, RevenuPourCourtier>
     */
    #[ORM\OneToMany(targetEntity: RevenuPourCourtier::class, mappedBy: 'cotation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $revenus;

    /**
     * @var Collection<int, Tranche>
     */
    #[ORM\OneToMany(targetEntity: Tranche::class, mappedBy: 'cotation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $tranches;

    #[ORM\ManyToOne(inversedBy: 'cotations')]
    private ?Piste $piste = null;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'cotation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    /**
     * @var Collection<int, Avenant>
     */
    #[ORM\OneToMany(targetEntity: Avenant::class, mappedBy: 'cotation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $avenants;

    public function __construct()
    {
        $this->taches = new ArrayCollection();
        $this->chargements = new ArrayCollection();
        $this->revenus = new ArrayCollection();
        $this->tranches = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->avenants = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function __toString()
    {
        return $this->nom . " / " . $this->piste->getNom();
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getDuree(): ?int
    {
        return $this->duree;
    }

    public function setDuree(int $duree): static
    {
        $this->duree = $duree;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createAt): static
    {
        $this->createdAt = $createAt;

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

    public function getAssureur(): ?Assureur
    {
        return $this->assureur;
    }

    public function setAssureur(?Assureur $assureur): static
    {
        $this->assureur = $assureur;

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
            $tach->setCotation($this);
        }

        return $this;
    }

    public function removeTach(Tache $tach): static
    {
        if ($this->taches->removeElement($tach)) {
            // set the owning side to null (unless already changed)
            if ($tach->getCotation() === $this) {
                $tach->setCotation(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ChargementPourPrime>
     */
    public function getChargements(): Collection
    {
        return $this->chargements;
    }

    public function addChargement(ChargementPourPrime $chargement): static
    {
        if (!$this->chargements->contains($chargement)) {
            $this->chargements->add($chargement);
            $chargement->setCotation($this);
        }

        return $this;
    }

    public function removeChargement(ChargementPourPrime $chargement): static
    {
        if ($this->chargements->removeElement($chargement)) {
            // set the owning side to null (unless already changed)
            if ($chargement->getCotation() === $this) {
                $chargement->setCotation(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, RevenuPourCourtier>
     */
    public function getRevenus(): Collection
    {
        return $this->revenus;
    }

    public function addRevenu(RevenuPourCourtier $revenu): static
    {
        if (!$this->revenus->contains($revenu)) {
            $this->revenus->add($revenu);
            $revenu->setCotation($this);
        }

        return $this;
    }

    public function removeRevenu(RevenuPourCourtier $revenu): static
    {
        if ($this->revenus->removeElement($revenu)) {
            // set the owning side to null (unless already changed)
            if ($revenu->getCotation() === $this) {
                $revenu->setCotation(null);
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
            $tranch->setCotation($this);
        }

        return $this;
    }

    public function removeTranch(Tranche $tranch): static
    {
        if ($this->tranches->removeElement($tranch)) {
            // set the owning side to null (unless already changed)
            if ($tranch->getCotation() === $this) {
                $tranch->setCotation(null);
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
            $document->setCotation($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getCotation() === $this) {
                $document->setCotation(null);
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
            $avenant->setCotation($this);
        }

        return $this;
    }

    public function removeAvenant(Avenant $avenant): static
    {
        if ($this->avenants->removeElement($avenant)) {
            // set the owning side to null (unless already changed)
            if ($avenant->getCotation() === $this) {
                $avenant->setCotation(null);
            }
        }

        return $this;
    }
}
