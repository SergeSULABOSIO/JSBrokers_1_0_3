<?php

namespace App\Entity;

use App\Repository\RevenuRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RevenuRepository::class)]
class Revenu
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(nullable: true)]
    private ?int $formule = null;

    public const FORMULE_POURCENTAGE_PRIME_NETTE = 0;
    public const FORMULE_POURCENTAGE_FRONTING = 1;
    public const FORMULE_POURCENTAGE_PRIME_TOTALE = 2;

    #[ORM\Column(nullable: true)]
    private ?float $montantflat = null;

    #[ORM\Column]
    private ?bool $shared = null;

    #[ORM\Column]
    private ?bool $multipayments = null;

    #[ORM\Column]
    private ?int $redevable = null;

    public const REDEVABLE_CLIENT = 0;
    public const REDEVABLE_ASSUREUR = 1;
    public const REDEVABLE_REASSURER = 2;
    public const REDEVABLE_PARTENAIRE = 3;

    #[ORM\Column(nullable: true)]
    private ?float $pourcentage = null;

    #[ORM\ManyToOne(inversedBy: 'revenus')]
    private ?Entreprise $entreprise = null;


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
}
