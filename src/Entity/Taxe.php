<?php

namespace App\Entity;

use App\Entity\Entreprise;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\TaxeRepository;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TaxeRepository::class)]
class Taxe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[Assert\NotBlank(message: "Ce champ ne peut pas être vide.")]
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    #[Assert\NotBlank(message: "Ce champ ne peut pas être vide.")]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Groups(['list:read'])]
    private ?string $tauxIARD = null;

    #[Assert\NotBlank(message: "Ce champ ne peut pas être vide.")]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Groups(['list:read'])]
    private ?string $tauxVIE = null;

    #[Assert\NotBlank(message: "Ce champ ne peut pas être vide.")]
    #[ORM\Column(length: 5)]
    #[Groups(['list:read'])]
    private ?string $code = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $redevable = null;

    public const REDEVABLE_ASSUREUR = 0;
    public const REDEVABLE_COURTIER = 1;

    #[ORM\ManyToOne(inversedBy: 'taxes')]
    #[Groups(['list:read'])]
    private ?Entreprise $entreprise = null;

    /**
     * @var Collection<int, AutoriteFiscale>
     */
    #[ORM\OneToMany(targetEntity: AutoriteFiscale::class, mappedBy: 'taxe', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['list:read'])]
    private Collection $autoriteFiscales;

    /**
     * @var Collection<int, Article>
     */
    #[ORM\OneToMany(targetEntity: Article::class, mappedBy: 'taxeFacturee')]
    private Collection $articles;

    // Attributs calculés pour l'affichage dans les listes
    #[Groups(['list:read'])]
    public ?float $tauxIARDPercent = null;

    #[Groups(['list:read'])]
    public ?float $tauxVIEPercent = null;

    // Attributs calculés pour la vue détaillée
    #[Groups(['list:read'])]
    public ?string $redevableString = null;

    #[Groups(['list:read'])]
    public ?int $nombreAutorites = null;

    public function __construct()
    {
        $this->autoriteFiscales = new ArrayCollection();
        $this->articles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        
        return $this;
    }

    // public function getOrganisation(): ?string
    // {
    //     return $this->organisation;
    // }

    // public function setOrganisation(string $organisation): self
    // {
        
    //     $this->organisation = $organisation;
        
    //     return $this;
    // }

    public function __toString()
    {
        $txt = " (" . (float)$this->tauxIARD * 100 . "%@IARD & " . (float)$this->tauxVIE * 100 . "%@VIE)";
        if ($this->tauxIARD == $this->tauxVIE) {
            $txt = " (" . (float)$this->tauxIARD * 100 . "%)";
        }
        return ($this->code ?? '') . $txt;
    }
    
    /**
     * Get the value of tauxIARD
     */
    public function getTauxIARD(): ?string
    {
        return $this->tauxIARD;
    }

    /**
     * Set the value of tauxIARD
     *
     * @return  self
     */
    public function setTauxIARD(?string $tauxIARD): self
    {
        $this->tauxIARD = $tauxIARD;
        return $this;
    }

    /**
     * Get the value of tauxVIE
     */
    public function getTauxVIE(): ?string
    {
        return $this->tauxVIE;
    }

    /**
     * Set the value of tauxVIE
     *
     * @return  self
     */
    public function setTauxVIE(?string $tauxVIE): self
    {
        $this->tauxVIE = $tauxVIE;
        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

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

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): static
    {
        $this->entreprise = $entreprise;

        return $this;
    }

    /**
     * @return Collection<int, AutoriteFiscale>
     */
    public function getAutoriteFiscales(): Collection
    {
        return $this->autoriteFiscales;
    }

    public function addAutoriteFiscale(AutoriteFiscale $autoriteFiscale): static
    {
        if (!$this->autoriteFiscales->contains($autoriteFiscale)) {
            $this->autoriteFiscales->add($autoriteFiscale);
            $autoriteFiscale->setTaxe($this);
        }

        return $this;
    }

    public function removeAutoriteFiscale(AutoriteFiscale $autoriteFiscale): static
    {
        if ($this->autoriteFiscales->removeElement($autoriteFiscale)) {
            // set the owning side to null (unless already changed)
            if ($autoriteFiscale->getTaxe() === $this) {
                $autoriteFiscale->setTaxe(null);
            }
        }

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
            $article->setTaxeFacturee($this);
        }

        return $this;
    }

    public function removeArticle(Article $article): static
    {
        if ($this->articles->removeElement($article)) {
            // set the owning side to null (unless already changed)
            if ($article->getTaxeFacturee() === $this) {
                $article->setTaxeFacturee(null);
            }
        }

        return $this;
    }
}
