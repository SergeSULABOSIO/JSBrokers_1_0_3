<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\ChargementRepository;
use Doctrine\Common\Collections\{ArrayCollection, Collection};
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: ChargementRepository::class)]
class Chargement
{
    
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    // #[ORM\Column(nullable: true)]
    // private ?float $montantflat = null;

    // #[ORM\Column]
    // private ?bool $imposable = null;
    
    // #[ORM\Column(nullable: true)]
    // private ?float $tauxSurPrimeNette = null;
    
    #[ORM\ManyToOne(inversedBy: 'chargements')]
    #[Groups(['list:read'])]
    private ?Entreprise $entreprise = null;

    /**
     * @var Collection<int, ChargementPourPrime>
     */
    #[ORM\OneToMany(targetEntity: ChargementPourPrime::class, mappedBy: 'type')]
    private Collection $chargementPourPrimes;

    /**
     * @var Collection<int, TypeRevenu>
     */
    #[ORM\OneToMany(targetEntity: TypeRevenu::class, mappedBy: 'typeChargement')]
    private Collection $typeRevenus;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?int $fonction = null;

    #[Groups(['list:read'])]
    public ?string $fonction_string;

    public const FONCTION_PRIME_NETTE = 1;
    public const FONCTION_FRONTING = 2;
    public const FONCTION_FRAIS_ADMIN = 3;
    public const FONCTION_TAXE = 4;


    public function __construct()
    {
        $this->chargementPourPrimes = new ArrayCollection();
        $this->typeRevenus = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    // public function getMontantflat(): ?float
    // {
    //     return $this->montantflat;
    // }

    // public function setMontantflat(?float $montantflat): static
    // {
    //     $this->montantflat = $montantflat;

    //     return $this;
    // }

    // public function isImposable(): ?bool
    // {
    //     return $this->imposable;
    // }

    // public function setImposable(bool $imposable): static
    // {
    //     $this->imposable = $imposable;

    //     return $this;
    // }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): static
    {
        $this->entreprise = $entreprise;

        return $this;
    }

    // public function getTauxSurPrimeNette(): ?float
    // {
    //     return $this->tauxSurPrimeNette;
    // }

    // public function setTauxSurPrimeNette(?float $tauxSurPrimeNette): static
    // {
    //     $this->tauxSurPrimeNette = $tauxSurPrimeNette;

    //     return $this;
    // }

    public function __toString(): string
    {
        return $this->nom;
    }

    /**
     * @return Collection<int, ChargementPourPrime>
     */
    public function getChargementPourPrimes(): Collection
    {
        return $this->chargementPourPrimes;
    }

    public function addChargementPourPrime(ChargementPourPrime $chargementPourPrime): static
    {
        if (!$this->chargementPourPrimes->contains($chargementPourPrime)) {
            $this->chargementPourPrimes->add($chargementPourPrime);
            $chargementPourPrime->setType($this);
        }

        return $this;
    }

    public function removeChargementPourPrime(ChargementPourPrime $chargementPourPrime): static
    {
        if ($this->chargementPourPrimes->removeElement($chargementPourPrime)) {
            // set the owning side to null (unless already changed)
            if ($chargementPourPrime->getType() === $this) {
                $chargementPourPrime->setType(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, TypeRevenu>
     */
    public function getTypeRevenus(): Collection
    {
        return $this->typeRevenus;
    }

    public function addTypeRevenu(TypeRevenu $typeRevenu): static
    {
        if (!$this->typeRevenus->contains($typeRevenu)) {
            $this->typeRevenus->add($typeRevenu);
            $typeRevenu->setTypeChargement($this);
        }

        return $this;
    }

    public function removeTypeRevenu(TypeRevenu $typeRevenu): static
    {
        if ($this->typeRevenus->removeElement($typeRevenu)) {
            // set the owning side to null (unless already changed)
            if ($typeRevenu->getTypeChargement() === $this) {
                $typeRevenu->setTypeChargement(null);
            }
        }

        return $this;
    }

    public function getFonction(): ?int
    {
        return $this->fonction;
    }

    public function setFonction(?int $fonction): static
    {
        $this->fonction = $fonction;

        return $this;
    }
}
