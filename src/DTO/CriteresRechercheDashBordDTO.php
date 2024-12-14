<?php

namespace App\DTO;

use App\Entity\Assureur;
use App\Entity\Client;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;


class CriteresRechercheDashBordDTO
{
    /**
     * @var Collection<int, Client>
     */
    private Collection $clients;

    /**
     * @var Collection<int, Assureur>
     */
    private Collection $assureurs;

    /**
     * @var Collection<int, Produit>
     */
    private Collection $produits;

    /**
     * @var Collection<int, Partenaire>
     */
    private Collection $partenaires;

    private DateTimeImmutable $dateDebut;

    private DateTimeImmutable $dateFin;


    public function __construct()
    {
        $this->clients = new ArrayCollection();
        $this->produits = new ArrayCollection();
        $this->assureurs = new ArrayCollection();
        $this->partenaires = new ArrayCollection();
        $this->dateDebut = new DateTimeImmutable();
        $this->dateFin = new DateTimeImmutable();
    }

    public function nbFiltresAvancesActif():int
    {
        // dd(count($this->getAssureurs()));
        $nbFiltreActifs = 0;
        if (count($this->getAssureurs()) != 0) {
            $nbFiltreActifs++;
        }
        if (count($this->getProduits()) != 0) {
            $nbFiltreActifs++;
        }
        if (count($this->getPartenaires()) != 0) {
            $nbFiltreActifs++;
        }
        
        // dd(count($this->getAssureurs()), count($this->getProduits()), count($this->getPartenaires()));
        // dd($nbFiltreActifs);
        return $nbFiltreActifs;
    }

    /**
     * Get the value of dateFin
     */ 
    public function getDateFin()
    {
        return $this->dateFin;
    }

    /**
     * Set the value of dateFin
     *
     * @return  self
     */ 
    public function setDateFin($dateFin)
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    /**
     * Get the value of dateDebut
     */ 
    public function getDateDebut()
    {
        return $this->dateDebut;
    }

    /**
     * Set the value of dateDebut
     *
     * @return  self
     */ 
    public function setDateDebut($dateDebut)
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    /**
     * Get entreprise>
     *
     * @return  Collection<int,
     */ 
    public function getPartenaires()
    {
        return $this->partenaires;
    }

    /**
     * Set entreprise>
     *
     * @param  Collection<int,  $partenaires  Partenaire>
     *
     * @return  self
     */ 
    public function setPartenaires(Collection $partenaires)
    {
        $this->partenaires = $partenaires;

        return $this;
    }

    /**
     * Get entreprise>
     *
     * @return  Collection<int,
     */ 
    public function getProduits()
    {
        return $this->produits;
    }

    /**
     * Set produit>
     *
     * @param  Collection<int,  $produits  Produit>
     *
     * @return  self
     */ 
    public function setProduits(Collection $produits)
    {
        $this->produits = $produits;

        return $this;
    }

    
    public function getAssureurs()
    {
        return $this->assureurs;
    }

    /**
     * Set assureur>
     *
     * @param  Collection<int,  $assureurs  Assureur>
     *
     * @return  self
     */ 
    public function setAssureurs(Collection $assureurs)
    {
        $this->assureurs = $assureurs;

        return $this;
    }

    /**
     * Get client>
     *
     * @return  Collection<int,
     */ 
    public function getClients()
    {
        return $this->clients;
    }

    /**
     * Set client>
     *
     * @param  Collection<int,  $client  Client>
     *
     * @return  self
     */ 
    public function setClients(Collection $clients)
    {
        $this->clients = $clients;

        return $this;
    }
}
