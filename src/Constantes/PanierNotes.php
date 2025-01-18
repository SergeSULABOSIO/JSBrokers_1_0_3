<?php

namespace App\Constantes;

use App\Entity\Note;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class PanierNotes
{
    public const NOM = "PANIER";
    private string $signature;
    private string $nomNote;
    private string $reference;
    private $idNote = null;
    private Collection $IdArticles;
    private int $type;
    private int $addressedTo;
    private int $idAssureur;
    private int $idPartenaire;
    private int $idClient;
    private int $idAutoriteFiscale;


    public function __construct() {
        $this->IdArticles = new ArrayCollection();
    }

    public function viderpanier(){
        $this->idNote = null;
        $this->nomNote = "";
        $this->signature = "";
        $this->reference = "";
        $this->IdArticles = new ArrayCollection();
    }

    public function setNote(?Note $note): self{
        $this->setIdNote($note->getId());
        $this->setNomNote($note->getNom());
        $this->setType($note->getType());
        $this->setAddressedTo($note->getAddressedTo());
        $this->setReference($note->getReference());
        $this->setSignature($note->getSignature());
        $this->setIdAssureur($note->getAssureur()->getId());
        $this->setIdClient($note->getClient()->getId());
        $this->setIdPartenaire($note->getPartenaire()->getId());
        $this->setIdAutoriteFiscale($note->getAutoritefiscale()->getId());
        $this->IdArticles = new ArrayCollection();
        foreach ($note->getArticles() as $article) {
            $this->addIdArticle($article->getId());
        }
        return $this;
    }

    public function __toString()
    {
        return self::NOM;
    }

    /**
     * @return Collection<int, AutoriteFiscale>
     */
    public function getIdArticles(): Collection
    {
        return $this->IdArticles;
    }

    public function addIdArticle(int $idArticle): static
    {
        if (!$this->IdArticles->contains($idArticle)) {
            $this->IdArticles->add($idArticle);
        }

        return $this;
    }

    public function removeIdArticle(int $idArticle): static
    {
        if ($this->IdArticles->contains($idArticle)) {
            $this->IdArticles->removeElement($idArticle);
        }

        return $this;
    }

    /**
     * Get the value of signature
     */ 
    public function getSignature()
    {
        return $this->signature;
    }

    /**
     * Set the value of signature
     *
     * @return  self
     */ 
    public function setSignature($signature)
    {
        $this->signature = $signature;

        return $this;
    }

    /**
     * Get the value of nomNote
     */ 
    public function getNomNote()
    {
        return $this->nomNote;
    }

    /**
     * Set the value of nomNote
     *
     * @return  self
     */ 
    public function setNomNote($nomNote)
    {
        $this->nomNote = $nomNote;

        return $this;
    }

    /**
     * Get the value of idNote
     */ 
    public function getIdNote()
    {
        return $this->idNote;
    }

    /**
     * Set the value of idNote
     *
     * @return  self
     */ 
    public function setIdNote($idNote)
    {
        $this->idNote = $idNote;

        return $this;
    }

    /**
     * Get the value of nbArticle
     */ 
    public function getNbArticle()
    {
        return count($this->getIdArticles());
    }

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
     * Set the value of articles
     *
     * @return  self
     */ 
    public function setArticles($articles)
    {
        $this->IdArticles = $articles;

        return $this;
    }

    /**
     * Set the value of IdArticles
     *
     * @return  self
     */ 
    public function setIdArticles(?ArrayCollection $IdArticles)
    {
        $this->IdArticles = $IdArticles;

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
     * Get the value of idAssureur
     */ 
    public function getIdAssureur()
    {
        return $this->idAssureur;
    }

    /**
     * Set the value of idAssureur
     *
     * @return  self
     */ 
    public function setIdAssureur($idAssureur)
    {
        $this->idAssureur = $idAssureur;

        return $this;
    }

    /**
     * Get the value of idPartenaire
     */ 
    public function getIdPartenaire()
    {
        return $this->idPartenaire;
    }

    /**
     * Set the value of idPartenaire
     *
     * @return  self
     */ 
    public function setIdPartenaire($idPartenaire)
    {
        $this->idPartenaire = $idPartenaire;

        return $this;
    }

    /**
     * Get the value of idClient
     */ 
    public function getIdClient()
    {
        return $this->idClient;
    }

    /**
     * Set the value of idClient
     *
     * @return  self
     */ 
    public function setIdClient($idClient)
    {
        $this->idClient = $idClient;

        return $this;
    }

    /**
     * Get the value of idAutoriteFiscale
     */ 
    public function getIdAutoriteFiscale()
    {
        return $this->idAutoriteFiscale;
    }

    /**
     * Set the value of idAutoriteFiscale
     *
     * @return  self
     */ 
    public function setIdAutoriteFiscale($idAutoriteFiscale)
    {
        $this->idAutoriteFiscale = $idAutoriteFiscale;

        return $this;
    }
}
