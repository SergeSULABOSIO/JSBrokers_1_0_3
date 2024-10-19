<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\TaxeRepository;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TaxeRepository::class)]
class Taxe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotBlank(message: "Ce champ ne peut pas être vide.")]
    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[Assert\NotBlank(message: "Ce champ ne peut pas être vide.")]
    #[ORM\Column(length: 255)]
    private ?string $description = null;

    #[Assert\NotBlank(message: "Ce champ ne peut pas être vide.")]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $tauxIARD = null;

    #[Assert\NotBlank(message: "Ce champ ne peut pas être vide.")]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $tauxVIE = null;

    #[Assert\NotBlank(message: "Ce champ ne peut pas être vide.")]
    #[ORM\Column(length: 255)]
    private ?string $organisation = null;

    #[ORM\Column]
    private ?bool $payableparcourtier = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

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

    public function setNom(string $nom): self
    {
        $this->nom = $nom;
        
        return $this;
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

    public function getOrganisation(): ?string
    {
        return $this->organisation;
    }

    public function setOrganisation(string $organisation): self
    {
        
        $this->organisation = $organisation;
        
        return $this;
    }

    public function __toString()
    {
        $txt = " (" . $this->tauxIARD * 100 . "%@IARD & " . $this->tauxVIE * 100 . "%@VIE)";
        if ($this->tauxIARD == $this->tauxVIE) {
            $txt = " (" . $this->tauxIARD * 100 . "%)";
        }
        return $this->nom . $txt;
    }

    public function isPayableparcourtier(): ?bool
    {
        return $this->payableparcourtier;
    }

    public function setPayableparcourtier(bool $payableparcourtier): self
    {
        $this->payableparcourtier = $payableparcourtier;
        
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    
    /**
     * Get the value of tauxIARD
     */
    public function getTauxIARD()
    {
        return $this->tauxIARD;
    }

    /**
     * Set the value of tauxIARD
     *
     * @return  self
     */
    public function setTauxIARD($tauxIARD)
    {
        
        $this->tauxIARD = $tauxIARD;
        
        return $this;
    }

    /**
     * Get the value of tauxVIE
     */
    public function getTauxVIE()
    {
        return $this->tauxVIE;
    }

    /**
     * Set the value of tauxVIE
     *
     * @return  self
     */
    public function setTauxVIE($tauxVIE)
    {
        
        $this->tauxVIE = $tauxVIE;
        
        return $this;
    }
}
