<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\RevenuPourCourtierRepository;
use Doctrine\Common\Collections\{ArrayCollection, Collection};
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: RevenuPourCourtierRepository::class)]
class RevenuPourCourtier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'revenuPourCourtiers')]
    #[Groups(['list:read'])]
    private ?TypeRevenu $typeRevenu = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $montantFlatExceptionel = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $tauxExceptionel = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'revenus')]
    #[Groups(['list:read'])]
    private ?Cotation $cotation = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    /**
     * @var Collection<int, Article>
     */
    #[ORM\OneToMany(targetEntity: Article::class, mappedBy: 'revenuFacture')]
    private Collection $articles;

    // Attributs calculés
    #[Groups(['list:read'])]
    public ?float $montantCalculeHT = null;

    #[Groups(['list:read'])]
    public ?float $montantCalculeTTC = null;

    #[Groups(['list:read'])]
    public ?string $descriptionCalcul = null;

    #[Groups(['list:read'])]
    public ?float $montant_du = null;

    #[Groups(['list:read'])]
    public ?float $montant_paye = null;

    #[Groups(['list:read'])]
    public ?float $solde_restant_du = null;

    #[Groups(['list:read'])]
    public ?float $montantPur = null;

    #[Groups(['list:read'])]
    public ?float $partPartenaire = null;

    #[Groups(['list:read'])]
    public ?float $retroCommission = null;

    #[Groups(['list:read'])]
    public ?float $reserve = null;

    #[Groups(['list:read'])]
    public ?float $retroCommissionReversee = null;

    #[Groups(['list:read'])]
    public ?float $retroCommissionSolde = null;

    #[Groups(['list:read'])]
    public ?float $taxeCourtierMontant = null;

    #[Groups(['list:read'])]
    public ?float $taxeCourtierTaux = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurMontant = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurTaux = null;
    public function __construct()
    {
        $this->articles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTypeRevenu(): ?TypeRevenu
    {
        return $this->typeRevenu;
    }

    public function setTypeRevenu(?TypeRevenu $typerevenu): static
    {
        $this->typeRevenu = $typerevenu;

        return $this;
    }

    public function getMontantFlatExceptionel(): ?float
    {
        return $this->montantFlatExceptionel;
    }

    public function setMontantFlatExceptionel(?float $montantFlatExceptionel): static
    {
        $this->montantFlatExceptionel = $montantFlatExceptionel;

        return $this;
    }

    public function getTauxExceptionel(): ?float
    {
        return $this->tauxExceptionel;
    }

    public function setTauxExceptionel(?float $tauxExceptionel): static
    {
        $this->tauxExceptionel = $tauxExceptionel;

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

    public function getCotation(): ?Cotation
    {
        return $this->cotation;
    }

    public function setCotation(?Cotation $cotation): static
    {
        $this->cotation = $cotation;

        return $this;
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
     * @return Collection<int, Article>
     */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    public function addArticle(Article $article): static
    {
        if (!$this->articles->contains($article)) {
            $this->articles->add($article);
            $article->setRevenuFacture($this);
        }

        return $this;
    }

    public function removeArticle(Article $article): static
    {
        if ($this->articles->removeElement($article)) {
            // set the owning side to null (unless already changed)
            if ($article->getRevenuFacture() === $this) {
                $article->setRevenuFacture(null);
            }
        }

        return $this;
    }
}
