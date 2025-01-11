<?php

namespace App\DTO;

use App\Entity\CompteBancaire;
use App\Entity\Paiement;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;

class NotePageADTO
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 4, max:255)]
    private ?string $reference = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 4, max:255)]
    private ?string $nom = null;

    #[Assert\NotBlank]
    private ?int $type = null;

    #[Assert\NotBlank]
    private ?int $addressedTo = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 4, max:400)]
    private ?string $description = null;

     /**
     * @var Collection<int, CompteBancaire>
     */
    public Collection $comptes;

    /**
     * @var Collection<int, Paiement>
     */
    public Collection $paiements;

    /**
     * Get the value of reference
     */ 
    public function getReference()
    {
        return $this->reference;
    }

    /**
     * Set the value of reference
     *
     * @return  self
     */ 
    public function setReference($reference)
    {
        $this->reference = $reference;

        return $this;
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
}
