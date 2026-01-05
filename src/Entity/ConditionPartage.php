<?php

namespace App\Entity;

use App\Repository\ConditionPartageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: ConditionPartageRepository::class)]
class ConditionPartage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $formule = null;
    public const FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL = 0;
    public const FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL = 1;
    public const FORMULE_NE_SAPPLIQUE_PAS_SEUIL = 2;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?float $seuil = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $taux = null;

    #[ORM\ManyToOne(inversedBy: 'conditionPartages')]
    #[Groups(['list:read'])]
    private ?Partenaire $partenaire = null;

    /**
     * @var Collection<int, Risque>
     */
    #[ORM\OneToMany(targetEntity: Risque::class, mappedBy: 'conditionPartage')]
    #[Groups(['list:read'])]
    private Collection $produits;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $critereRisque = null;
    public const CRITERE_EXCLURE_TOUS_CES_RISQUES = 0;
    public const CRITERE_INCLURE_TOUS_CES_RISQUES = 1;
    public const CRITERE_PAS_RISQUES_CIBLES = 2;


    #[ORM\ManyToOne(inversedBy: 'conditionsPartageExceptionnelles')]
    #[Groups(['list:read'])]
    private ?Piste $piste = null;

    public const UNITE_SOMME_COMMISSION_PURE_RISQUE = 0;
    public const UNITE_SOMME_COMMISSION_PURE_CLIENT = 1;
    public const UNITE_SOMME_COMMISSION_PURE_PARTENAIRE = 2;
    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?int $uniteMesure = null;

    #[Groups(['list:read'])]
    public ?string $formule_string;

    #[Groups(['list:read'])]
    public ?string $critere_risque_string;

    #[Groups(['list:read'])]
    public ?string $unite_mesure_string;

  
    

    public function __construct()
    {
        $this->produits = new ArrayCollection();
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

    public function getFormule(): ?int
    {
        return $this->formule;
    }

    public function setFormule(int $formule): static
    {
        $this->formule = $formule;

        return $this;
    }

    public function getSeuil(): ?float
    {
        return $this->seuil;
    }

    public function setSeuil(float $seuil): static
    {
        $this->seuil = $seuil;

        return $this;
    }

    public function getTaux(): ?float
    {
        return $this->taux;
    }

    public function setTaux(?float $taux): static
    {
        $this->taux = $taux;

        return $this;
    }

    public function getPartenaire(): ?Partenaire
    {
        return $this->partenaire;
    }

    public function setPartenaire(?Partenaire $partenaire): static
    {
        $this->partenaire = $partenaire;

        return $this;
    }

    /**
     * @return Collection<int, Risque>
     */
    public function getProduits(): Collection
    {
        return $this->produits;
    }

    public function addProduit(Risque $produit): static
    {
        if (!$this->produits->contains($produit)) {
            $this->produits->add($produit);
            $produit->setConditionPartage($this);
        }

        return $this;
    }

    public function removeProduit(Risque $produit): static
    {
        if ($this->produits->removeElement($produit)) {
            // set the owning side to null (unless already changed)
            if ($produit->getConditionPartage() === $this) {
                $produit->setConditionPartage(null);
            }
        }

        return $this;
    }

    public function getCritereRisque(): ?int
    {
        return $this->critereRisque;
    }

    public function setCritereRisque(int $critereRisque): static
    {
        $this->critereRisque = $critereRisque;

        return $this;
    }

    // public function getUnite(): ?int
    // {
    //     return $this->unite;
    // }

    // public function setUnite(int $unite): static
    // {
    //     $this->unite = $unite;

    //     return $this;
    // }

    public function getPiste(): ?Piste
    {
        return $this->piste;
    }

    public function setPiste(?Piste $piste): static
    {
        $this->piste = $piste;

        return $this;
    }

    public function getUniteMesure(): ?int
    {
        return $this->uniteMesure;
    }

    public function setUniteMesure(?int $uniteMesure): static
    {
        $this->uniteMesure = $uniteMesure;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom ?? 'Nouvelle condition';
    }
}
