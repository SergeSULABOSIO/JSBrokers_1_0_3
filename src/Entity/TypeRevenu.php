<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\TypeRevenuRepository;
use Doctrine\Common\Collections\{ArrayCollection, Collection};
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: TypeRevenuRepository::class)]
class TypeRevenu
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    // #[ORM\Column(nullable: true)]
    // private ?int $formule = null;

    public const MODE_CALCUL_POURCENTAGE_CHARGEMENT = 0;
    public const MODE_CALCUL_MONTANT_FLAT = 1;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $montantflat = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?bool $shared = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?bool $multipayments = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $redevable = null;

    public const REDEVABLE_CLIENT = 0;
    public const REDEVABLE_ASSUREUR = 1;
    public const REDEVABLE_REASSURER = 2;
    public const REDEVABLE_PARTENAIRE = 3;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $pourcentage = null;

    #[ORM\ManyToOne(inversedBy: 'typerevenus')]
    #[Groups(['list:read'])]
    private ?Entreprise $entreprise = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?bool $appliquerPourcentageDuRisque = null;

    /**
     * @var Collection<int, RevenuPourCourtier>
     */
    #[ORM\OneToMany(targetEntity: RevenuPourCourtier::class, mappedBy: 'typeRevenu')]
    private Collection $revenuPourCourtiers;

    #[ORM\ManyToOne(inversedBy: 'typeRevenus')]
    #[Groups(['list:read'])]
    private ?Chargement $typeChargement = null;

    #[ORM\ManyToOne(inversedBy: 'revenus')]
    #[Groups(['list:read'])]
    private ?Note $note = null;

    #[ORM\ManyToOne(inversedBy: 'typeRevenu')]
    #[Groups(['list:read'])]
    private ?Article $article = null;

    // /**
    //  * @var Collection<int, Article>
    //  */
    // #[ORM\ManyToMany(targetEntity: Article::class, mappedBy: 'revenus')]
    // private Collection $articles;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?int $modeCalcul = null;

    public function __construct()
    {
        $this->revenuPourCourtiers = new ArrayCollection();
        // $this->articles = new ArrayCollection();
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

    // public function getFormule(): ?int
    // {
    //     return $this->formule;
    // }

    // public function setFormule(int $formule): static
    // {
    //     $this->formule = $formule;

    //     return $this;
    // }

    public function getMontantflat(): ?float
    {
        return $this->montantflat;
    }

    public function setMontantflat(?float $montantflat): static
    {
        $this->montantflat = $montantflat;

        return $this;
    }

    public function isShared(): ?bool
    {
        return $this->shared;
    }

    public function setShared(bool $shared): static
    {
        $this->shared = $shared;

        return $this;
    }

    public function isMultipayments(): ?bool
    {
        return $this->multipayments;
    }

    public function setMultipayments(bool $multipayments): static
    {
        $this->multipayments = $multipayments;

        return $this;
    }

    public function getRedevable(): ?int
    {
        return $this->redevable;
    }

    public function setRedevable(int $redevable): static
    {
        $this->redevable = $redevable;

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

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): static
    {
        $this->entreprise = $entreprise;

        return $this;
    }

    public function isAppliquerPourcentageDuRisque(): ?bool
    {
        return $this->appliquerPourcentageDuRisque;
    }

    public function setAppliquerPourcentageDuRisque(?bool $appliquerPourcentageDuRisque): static
    {
        $this->appliquerPourcentageDuRisque = $appliquerPourcentageDuRisque;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom;
    }

    /**
     * @return Collection<int, RevenuPourCourtier>
     */
    public function getRevenuPourCourtiers(): Collection
    {
        return $this->revenuPourCourtiers;
    }

    public function addRevenuPourCourtier(RevenuPourCourtier $revenuPourCourtier): static
    {
        if (!$this->revenuPourCourtiers->contains($revenuPourCourtier)) {
            $this->revenuPourCourtiers->add($revenuPourCourtier);
            $revenuPourCourtier->setTypeRevenu($this);
        }

        return $this;
    }

    public function removeRevenuPourCourtier(RevenuPourCourtier $revenuPourCourtier): static
    {
        if ($this->revenuPourCourtiers->removeElement($revenuPourCourtier)) {
            // set the owning side to null (unless already changed)
            if ($revenuPourCourtier->getTypeRevenu() === $this) {
                $revenuPourCourtier->setTypeRevenu(null);
            }
        }

        return $this;
    }

    public function getTypeChargement(): ?Chargement
    {
        return $this->typeChargement;
    }

    public function setTypeChargement(?Chargement $typeChargement): static
    {
        $this->typeChargement = $typeChargement;

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

    public function getArticle(): ?Article
    {
        return $this->article;
    }

    public function setArticle(?Article $article): static
    {
        $this->article = $article;

        return $this;
    }

    // /**
    //  * @return Collection<int, Article>
    //  */
    // public function getArticles(): Collection
    // {
    //     return $this->articles;
    // }

    // public function addArticle(Article $article): static
    // {
    //     if (!$this->articles->contains($article)) {
    //         $this->articles->add($article);
    //         $article->addRevenu($this);
    //     }

    //     return $this;
    // }

    // public function removeArticle(Article $article): static
    // {
    //     if ($this->articles->removeElement($article)) {
    //         $article->removeRevenu($this);
    //     }

    //     return $this;
    // }

    public function getModeCalcul(): ?int
    {
        return $this->modeCalcul;
    }

    public function setModeCalcul(?int $modeCalcul): static
    {
        $this->modeCalcul = $modeCalcul;

        return $this;
    }
}
