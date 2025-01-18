<?php

namespace App\Constantes;

use App\Entity\Note;

class PanierNotes
{
    public const NOM = "PANIER";
    private string $signature;
    private string $nomNote;
    private $idNote = null;
    private $nbArticle = null;


    public function __construct() {}

    public function viderpanier(){
        $this->idNote = null;
        $this->nbArticle = 0;
        $this->nomNote = null;
        $this->signature = null;
    }

    public function __toString()
    {
        return self::NOM;
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
        return $this->nbArticle;
    }

    /**
     * Set the value of nbArticle
     *
     * @return  self
     */ 
    public function setNbArticle($nbArticle)
    {
        $this->nbArticle = $nbArticle;

        return $this;
    }
}
