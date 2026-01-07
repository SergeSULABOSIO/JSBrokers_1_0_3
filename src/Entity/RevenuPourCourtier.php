<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;
use App\Entity\Traits\CalculatedIndicatorsTrait;
use App\Repository\RevenuPourCourtierRepository;
use Doctrine\Common\Collections\ArrayCollection;

#[ORM\Entity(repositoryClass: RevenuPourCourtierRepository::class)]
class RevenuPourCourtier
{
    use CalculatedIndicatorsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'revenuPourCourtiers')]
    private ?TypeRevenu $typeRevenu = null;

    #[ORM\Column(nullable: true)]
    private ?float $montantFlatExceptionel = null;

    #[ORM\Column(nullable: true)]
    private ?float $tauxExceptionel = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'revenus')]
    private ?Cotation $cotation = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    /**
     * @var Collection<int, Article>
     */
    #[ORM\OneToMany(targetEntity: Article::class, mappedBy: 'revenuFacture')]
    private Collection $articles;

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

    // public function addArticle(Article $article): static
    // {
    //     if (!$this->articles->contains($article)) {
    //         $this->articles->add($article);
    //         $article->setRevenuFacture($this);
    //     }

    //     return $this;
    // }

    // public function removeArticle(Article $article): static
    // {
    //     if ($this->articles->removeElement($article)) {
    //         // set the owning side to null (unless already changed)
    //         if ($article->getRevenuFacture() === $this) {
    //             $article->setRevenuFacture(null);
    //         }
    //     }

    //     return $this;
    // }
}
