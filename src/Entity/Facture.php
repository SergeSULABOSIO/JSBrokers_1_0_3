<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\FactureRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;

#[ORM\Entity(repositoryClass: FactureRepository::class)]
class Facture
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    /**
     * @var Collection<int, Tranche>
     */
    #[ORM\OneToMany(targetEntity: Tranche::class, mappedBy: 'facture')]
    private Collection $articles;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 255)]
    private ?string $reference = null;

    #[ORM\Column]
    private ?float $montantDu = null;

    #[ORM\Column(length: 255)]
    private ?string $debiteur = null;

    /**
     * @var Collection<int, Bordereau>
     */
    #[ORM\OneToMany(targetEntity: Bordereau::class, mappedBy: 'facture')]
    private Collection $bordereaux;

    /**
     * @var Collection<int, Paiement>
     */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'facture')]
    private Collection $paiements;

    #[ORM\ManyToOne(inversedBy: 'factures')]
    private ?Invite $invite = null;

    public function __construct()
    {
        $this->articles = new ArrayCollection();
        $this->bordereaux = new ArrayCollection();
        $this->paiements = new ArrayCollection();
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

    /**
     * @return Collection<int, Tranche>
     */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    public function addArticle(Tranche $article): static
    {
        if (!$this->articles->contains($article)) {
            $this->articles->add($article);
            $article->setFacture($this);
        }

        return $this;
    }

    public function removeArticle(Tranche $article): static
    {
        if ($this->articles->removeElement($article)) {
            // set the owning side to null (unless already changed)
            if ($article->getFacture() === $this) {
                $article->setFacture(null);
            }
        }

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

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

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

    public function getMontantDu(): ?float
    {
        return $this->montantDu;
    }

    public function setMontantDu(float $montantDu): static
    {
        $this->montantDu = $montantDu;

        return $this;
    }

    public function getDebiteur(): ?string
    {
        return $this->debiteur;
    }

    public function setDebiteur(string $debiteur): static
    {
        $this->debiteur = $debiteur;

        return $this;
    }

    /**
     * @return Collection<int, Bordereau>
     */
    public function getBordereaux(): Collection
    {
        return $this->bordereaux;
    }

    public function addBordereaux(Bordereau $bordereaux): static
    {
        if (!$this->bordereaux->contains($bordereaux)) {
            $this->bordereaux->add($bordereaux);
            $bordereaux->setFacture($this);
        }

        return $this;
    }

    public function removeBordereaux(Bordereau $bordereaux): static
    {
        if ($this->bordereaux->removeElement($bordereaux)) {
            // set the owning side to null (unless already changed)
            if ($bordereaux->getFacture() === $this) {
                $bordereaux->setFacture(null);
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
            $paiement->setFacture($this);
        }

        return $this;
    }

    public function removePaiement(Paiement $paiement): static
    {
        if ($this->paiements->removeElement($paiement)) {
            // set the owning side to null (unless already changed)
            if ($paiement->getFacture() === $this) {
                $paiement->setFacture(null);
            }
        }

        return $this;
    }
}
