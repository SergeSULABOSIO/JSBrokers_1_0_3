<?php

namespace App\DTO;

use App\Entity\Assureur;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\Paiement;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;


class NotePageADTO
{

    #[Assert\NotBlank]
    #[Assert\Length(min: 4, max:50)]
    private ?string $nom = null;

    #[Assert\NotBlank]
    private ?int $type = null;

    #[Assert\NotBlank]
    private ?int $addressedTo = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 4, max:200)]
    private ?string $description = null;

    /**
     * @var Collection<int, CompteBancaire>
     */
    private Collection $comptes;


    /**
     * @var Collection<int, Paiement>
     */
    private Collection $paiements;


    public function __construct()
    {
        $this->comptes = new ArrayCollection();
        $this->paiements = new ArrayCollection();
    }

    /**
     * Get the value of nom
     */ 
    public function getNom()
    {
        return $this->nom;
    }

    /**
     * Set the value of nom
     *
     * @return  self
     */ 
    public function setNom($nom)
    {
        $this->nom = $nom;

        return $this;
    }

    /**
     * Get the value of type
     */ 
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set the value of type
     *
     * @return  self
     */ 
    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Get the value of addressedTo
     */ 
    public function getAddressedTo()
    {
        return $this->addressedTo;
    }

    /**
     * Set the value of addressedTo
     *
     * @return  self
     */ 
    public function setAddressedTo($addressedTo)
    {
        $this->addressedTo = $addressedTo;

        return $this;
    }

    /**
     * Get the value of description
     */ 
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set the value of description
     *
     * @return  self
     */ 
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return Collection<int, CompteBancaire>
     */
    public function getComptes(): Collection
    {
        return $this->comptes;
    }

    public function addCompte(CompteBancaire $compte): static
    {
        if (!$this->comptes->contains($compte)) {
            $this->comptes->add($compte);
        }

        return $this;
    }

    public function removeCompte(CompteBancaire $compte): static
    {
        $this->comptes->removeElement($compte);

        return $this;
    }

    /**
     * @return Collection<int, Paiement>
     */
    public function getPaiements(): Collection
    {
        return $this->paiements;
    }

    public function addPaiement(Paiement $paiement): static
    {
        if (!$this->paiements->contains($paiement)) {
            $this->paiements->add($paiement);
        }

        return $this;
    }

    public function removePaiement(Paiement $paiement): static
    {
        if ($this->paiements->removeElement($paiement)) {
            // set the owning side to null (unless already changed)
            if ($paiement->getNote() === $this) {
                $paiement->setNote(null);
            }
        }

        return $this;
    }
}
