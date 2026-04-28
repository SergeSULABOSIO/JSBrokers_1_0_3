<?php

namespace App\Entity;

use App\Repository\OperationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: OperationRepository::class)]
#[ORM\Table(name: '`operation`')]
class Operation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $referencePolice = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $numeroAvenant = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?float $montantHT = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $montantTaxe = null;

    #[ORM\ManyToOne(inversedBy: 'operations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Bordereau $bordereau = null;

    #[Groups(['list:read'])]
    public ?float $montantTTC = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReferencePolice(): ?string
    {
        return $this->referencePolice;
    }

    public function setReferencePolice(string $referencePolice): static
    {
        $this->referencePolice = $referencePolice;

        return $this;
    }

    public function getNumeroAvenant(): ?string
    {
        return $this->numeroAvenant;
    }

    public function setNumeroAvenant(string $numeroAvenant): static
    {
        $this->numeroAvenant = $numeroAvenant;

        return $this;
    }

    public function getMontantHT(): ?float
    {
        return $this->montantHT;
    }

    public function setMontantHT(float $montantHT): static
    {
        $this->montantHT = $montantHT;

        return $this;
    }

    public function getMontantTaxe(): ?float
    {
        return $this->montantTaxe;
    }

    public function setMontantTaxe(?float $montantTaxe): static
    {
        $this->montantTaxe = $montantTaxe;

        return $this;
    }

    public function getBordereau(): ?Bordereau
    {
        return $this->bordereau;
    }

    public function setBordereau(?Bordereau $bordereau): static
    {
        $this->bordereau = $bordereau;

        return $this;
    }
}