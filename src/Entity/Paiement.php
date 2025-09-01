<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\PaiementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaiementRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Paiement
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $description = null;

    #[ORM\Column]
    private ?float $montant = null;

    #[ORM\Column(length: 255)]
    private ?string $reference = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $paidAt = null;

    // #[ORM\Column]
    // private ?\DateTimeImmutable $createdAt = null;

    // #[ORM\Column]
    // private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'paiements')]
    private ?OffreIndemnisationSinistre $offreIndemnisationSinistre = null;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'paiement', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $preuves;

    #[ORM\ManyToOne(inversedBy: 'paiements')]
    private ?Note $note = null;

    #[ORM\ManyToOne(inversedBy: 'paiements')]
    private ?CompteBancaire $CompteBancaire = null;


    public function __construct()
    {
        $this->preuves = new ArrayCollection();
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

    public function getMontant(): ?float
    {
        return $this->montant;
    }

    public function setMontant(float $montant): static
    {
        $this->montant = $montant;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
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

    // public function getUpdatedAt(): ?\DateTimeImmutable
    // {
    //     return $this->updatedAt;
    // }

    // public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    // {
    //     $this->updatedAt = $updatedAt;

    //     return $this;
    // }

    public function getOffreIndemnisationSinistre(): ?OffreIndemnisationSinistre
    {
        return $this->offreIndemnisationSinistre;
    }

    public function setOffreIndemnisationSinistre(?OffreIndemnisationSinistre $offreIndemnisationSinistre): static
    {
        $this->offreIndemnisationSinistre = $offreIndemnisationSinistre;

        return $this;
    }

    /**
     * @return Collection<int, Document>
     */
    public function getPreuves(): Collection
    {
        return $this->preuves;
    }

    public function addPreuve(Document $preufe): static
    {
        if (!$this->preuves->contains($preufe)) {
            $this->preuves->add($preufe);
            $preufe->setPaiement($this);
        }

        return $this;
    }

    public function removePreuve(Document $preufe): static
    {
        if ($this->preuves->removeElement($preufe)) {
            // set the owning side to null (unless already changed)
            if ($preufe->getPaiement() === $this) {
                $preufe->setPaiement(null);
            }
        }

        return $this;
    }

    public function getNote(): ?Note
    {
        return $this->note;
    }

    public function setNote(?Note $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getCompteBancaire(): ?CompteBancaire
    {
        return $this->CompteBancaire;
    }

    public function setCompteBancaire(?CompteBancaire $CompteBancaire): static
    {
        $this->CompteBancaire = $CompteBancaire;

        return $this;
    }
}
