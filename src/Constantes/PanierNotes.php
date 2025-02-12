<?php

namespace App\Constantes;

use App\Entity\Note;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class PanierNotes
{
    public const NOM = "PANIER";
    private string $signature;
    private string $nomNote;
    private string $reference;
    private $idNote = null;
    private Collection $idTranches;
    private Collection $montantsArticles;
    private Collection $postesFacturables;
    private int $type;
    private int $addressedTo;
    private int $idAssureur;
    private int $idPartenaire;
    private int $idClient;
    private int $idAutoriteFiscale;
    private DateTimeImmutable $createdAt;


    public function __construct() {
        $this->idTranches = new ArrayCollection();
        $this->montantsArticles = new ArrayCollection();
        $this->postesFacturables = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable("now");
    }

    public function viderpanier(){
        $this->idNote = null;
        $this->nomNote = "";
        $this->signature = "";
        $this->reference = "";
        $this->idTranches = new ArrayCollection();
        $this->montantsArticles = new ArrayCollection();
        $this->postesFacturables = new ArrayCollection();
    }

    public function containsTranche(int $idTranche): bool{
        return $this->idTranches->contains($idTranche);
    }


    public function isInvoiced(int $idTranche, float $montantArticle, string $poteFacturable): bool{
        if ($this->idTranches->contains($idTranche)) {
            $indexTranche = $this->idTranches->indexOf($idTranche);
            $montantArticleStocke = $this->montantsArticles->get($indexTranche);
            $posteArticleStocke = $this->postesFacturables->get($indexTranche);
            // dd("Ici...", $montantArticle, $montantArticleStocke);
            return ($montantArticle == $montantArticleStocke) && ($posteArticleStocke == $poteFacturable);
        }else{
            return false;
        }
    }

    public function setNote(?Note $note): self{
        $this->setIdNote($note->getId());
        $this->setNomNote($note->getNom());
        $this->setType($note->getType());
        $this->setAddressedTo($note->getAddressedTo());
        $this->setReference($note->getReference());
        $this->setSignature($note->getSignature());
        $this->setIdAssureur($note->getAssureur() ? $note->getAssureur()->getId(): -1);
        $this->setIdClient($note->getClient() ? $note->getClient()->getId(): -1);
        $this->setIdPartenaire($note->getPartenaire() ? $note->getPartenaire()->getId():-1);
        $this->setIdAutoriteFiscale($note->getAutoritefiscale() ? $note->getAutoritefiscale()->getId():-1);
        $this->idTranches = new ArrayCollection();
        $this->montantsArticles = new ArrayCollection();
        $this->postesFacturables = new ArrayCollection();
        foreach ($note->getArticles() as $article) {
            $this->addIdTranche($article->getTranche()->getId());
            $this->addMontantsArticles($article->getMontant());
            $this->addPostesFacturables($article->getNom());
        }
        return $this;
    }

    public function __toString()
    {
        return self::NOM;
    }

    
    public function getIdTranches(): ArrayCollection
    {
        return $this->idTranches;
    }

    public function addIdTranche(int $idTranche): static
    {
        if (!$this->idTranches->contains($idTranche)) {
            $this->idTranches->add($idTranche);
        }

        return $this;
    }

    public function removeIdTranche(int $idTranche): static
    {
        if ($this->idTranches->contains($idTranche)) {
            $this->idTranches->removeElement($idTranche);
        }

        return $this;
    }

    
    public function getMontantsArticles(): ArrayCollection
    {
        return $this->montantsArticles;
    }

    public function addMontantsArticles(float $montantArticle): static
    {
        if (!$this->montantsArticles->contains($montantArticle)) {
            $this->montantsArticles->add($montantArticle);
        }

        return $this;
    }

    public function removeMontantsArticles(float $montantArticle): static
    {
        if ($this->montantsArticles->contains($montantArticle)) {
            $this->montantsArticles->removeElement($montantArticle);
        }

        return $this;
    }



    public function postesFacturables(): ArrayCollection
    {
        return $this->postesFacturables();
    }

    public function addPostesFacturables(string $posteFacturable): static
    {
        if (!$this->postesFacturables->contains($posteFacturable)) {
            $this->postesFacturables->add($posteFacturable);
        }

        return $this;
    }

    public function removePostesFacturables(string $posteFacturable): static
    {
        if ($this->postesFacturables->contains($posteFacturable)) {
            $this->postesFacturables->removeElement($posteFacturable);
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
    public function getNbTranche()
    {
        return count($this->getIdTranches());
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
    public function setTranches($tranches)
    {
        $this->idTranches = $tranches;

        return $this;
    }

    /**
     * Set the value of IdArticles
     *
     * @return  self
     */ 
    public function setIdTranches(?ArrayCollection $idTranches)
    {
        $this->idTranches = $idTranches;

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

    /**
     * Get the value of createdAt
     */ 
    public function getCreatedAt()
    {
        return $this->createdAt;
    }
}
