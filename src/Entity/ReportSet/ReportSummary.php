<?php
namespace App\Entity\ReportSet;

use App\Entity\Utilisateur;
use DateTimeImmutable;

class ReportSummary
{

    public const RUBRIQUE = "rubrique";
    public const VALEUR = "valeur";

    public string $icone;
    public string $icone_color;
    public string $titre;
    public string $currency_code;
    public array $principal;
    public array $items;

    public function __construct()
    {
        
    }
    

    /**
     * Get the value of icone
     */ 
    public function getIcone()
    {
        return $this->icone;
    }

    /**
     * Set the value of icone
     *
     * @return  self
     */ 
    public function setIcone($icone)
    {
        $this->icone = $icone;

        return $this;
    }

    /**
     * Get the value of icone_color
     */ 
    public function getIcone_color()
    {
        return $this->icone_color;
    }

    /**
     * Set the value of icone_color
     *
     * @return  self
     */ 
    public function setIcone_color($icone_color)
    {
        $this->icone_color = $icone_color;

        return $this;
    }

    /**
     * Get the value of titre
     */ 
    public function getTitre()
    {
        return $this->titre;
    }

    /**
     * Set the value of titre
     *
     * @return  self
     */ 
    public function setTitre($titre)
    {
        $this->titre = $titre;

        return $this;
    }

    /**
     * Get the value of principal
     */ 
    public function getPrincipal()
    {
        return $this->principal;
    }

    /**
     * Set the value of principal
     *
     * @return  self
     */ 
    public function setPrincipal($principal)
    {
        $this->principal = $principal;

        return $this;
    }

    /**
     * Get the value of items
     */ 
    public function getItems()
    {
        return $this->items;
    }

    /**
     * Set the value of items
     *
     * @return  self
     */ 
    public function setItems($items)
    {
        $this->items = $items;

        return $this;
    }

    /**
     * Get the value of currency_code
     */ 
    public function getCurrency_code()
    {
        return $this->currency_code;
    }

    /**
     * Set the value of currency_code
     *
     * @return  self
     */ 
    public function setCurrency_code($currency_code)
    {
        $this->currency_code = $currency_code;

        return $this;
    }
}
