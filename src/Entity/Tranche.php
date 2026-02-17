<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\TrancheRepository;
use Doctrine\Common\Collections\Collection;
use App\Entity\Traits\CalculatedIndicatorsTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: TrancheRepository::class)]
class Tranche
{
    use CalculatedIndicatorsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(nullable: true)]
    private ?float $montantFlat = null;

    #[ORM\Column(nullable: true)]
    private ?float $pourcentage = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $payableAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'tranches')]
    private ?Cotation $cotation = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $echeanceAt = null;

    /**
     * @var Collection<int, Article>
     */
    #[ORM\OneToMany(targetEntity: Article::class, mappedBy: 'tranche')]
    private Collection $articles;

    #[Groups(['list:read'])]
    public ?string $contexteParent = null;

    #[Groups(['list:read'])]
    public ?string $ageTranche = null;

    #[Groups(['list:read'])]
    public ?string $joursRestantsAvantEcheance = null;

    public function __construct()
    {
        $this->articles = new ArrayCollection();
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

    public function getMontantFlat(): ?float
    {
        return $this->montantFlat;
    }

    public function setMontantFlat(?float $montantFlat): static
    {
        $this->montantFlat = $montantFlat;

        return $this;
    }

    public function getPourcentage(): ?float
    {
        return $this->pourcentage;
    }

    public function setPourcentage(?float $pourcentage): static
    {
        $this->pourcentage = $pourcentage;

        return $this;
    }

    public function getPayableAt(): ?\DateTimeImmutable
    {
        return $this->payableAt;
    }

    public function setPayableAt(\DateTimeImmutable $payableAt): static
    {
        $this->payableAt = $payableAt;

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

    public function getEcheanceAt(): ?\DateTimeImmutable
    {
        return $this->echeanceAt;
    }

    public function setEcheanceAt(?\DateTimeImmutable $echeanceAt): static
    {
        $this->echeanceAt = $echeanceAt;

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
            $article->setTranche($this);
        }

        return $this;
    }

    public function removeArticle(Article $article): static
    {
        if ($this->articles->removeElement($article)) {
            // set the owning side to null (unless already changed)
            if ($article->getTranche() === $this) {
                $article->setTranche(null);
            }
        }

        return $this;
    }

    public function __toString()
    {
        return ($this->cotation != null ? $this->cotation->getNom() : "") . " / " . $this->id . " / " . $this->nom;
    }
}
