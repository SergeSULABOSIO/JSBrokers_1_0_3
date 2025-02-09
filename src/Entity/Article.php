<?php

namespace App\Entity;

use App\Repository\ArticleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ArticleRepository::class)]
class Article
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\ManyToOne(inversedBy: 'articles')]
    private ?Tranche $tranche = null;

    #[ORM\Column]
    private ?float $pourcentage = null;

    #[ORM\ManyToOne(inversedBy: 'articles')]
    private ?Note $note = null;

    #[ORM\ManyToOne(inversedBy: 'articles')]
    private ?RevenuPourCourtier $revenuFacture = null;

    #[ORM\ManyToOne(inversedBy: 'articles')]
    private ?Taxe $taxeFacturee = null;

    public function __construct()
    {

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

    public function getTranche(): ?Tranche
    {
        return $this->tranche;
    }

    public function setTranche(?Tranche $tranche): static
    {
        $this->tranche = $tranche;

        return $this;
    }

    public function getPourcentage(): ?float
    {
        return $this->pourcentage;
    }

    public function setPourcentage(float $pourcentage): static
    {
        $this->pourcentage = $pourcentage;

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

    public function getRevenuFacture(): ?RevenuPourCourtier
    {
        return $this->revenuFacture;
    }

    public function setRevenuFacture(?RevenuPourCourtier $revenuFacture): static
    {
        $this->revenuFacture = $revenuFacture;

        return $this;
    }

    public function getTaxeFacturee(): ?Taxe
    {
        return $this->taxeFacturee;
    }

    public function setTaxeFacturee(?Taxe $taxeFacturee): static
    {
        $this->taxeFacturee = $taxeFacturee;

        return $this;
    }
}
