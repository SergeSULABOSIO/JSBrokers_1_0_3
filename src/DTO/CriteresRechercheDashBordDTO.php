<?php

namespace App\DTO;

use App\Entity\Entreprise;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use function PHPUnit\Framework\isEmpty;

class CriteresRechercheDashBordDTO
{
    /**
     * @var Collection<int, Entreprise>
     */
    private Collection $entreprises;

    /**
     * @var Collection<int, Entreprise>
     */
    private Collection $assureurs;

    /**
     * @var Collection<int, Entreprise>
     */
    private Collection $produits;

    /**
     * @var Collection<int, Entreprise>
     */
    private Collection $partenaires;

    private DateTimeImmutable $dateDebut;

    private DateTimeImmutable $dateFin;


    public function __construct()
    {
        $this->entreprises = new ArrayCollection();
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
     * @param  Collection<int,  $partenaires  Entreprise>
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
     * Set entreprise>
     *
     * @param  Collection<int,  $produits  Entreprise>
     *
     * @return  self
     */ 
    public function setProduits(Collection $produits)
    {
        $this->produits = $produits;

        return $this;
    }

    /**
     * Get entreprise>
     *
     * @return  Collection<int,
     */ 
    public function getAssureurs()
    {
        return $this->assureurs;
    }

    /**
     * Set entreprise>
     *
     * @param  Collection<int,  $assureurs  Entreprise>
     *
     * @return  self
     */ 
    public function setAssureurs(Collection $assureurs)
    {
        $this->assureurs = $assureurs;

        return $this;
    }

    /**
     * Get entreprise>
     *
     * @return  Collection<int,
     */ 
    public function getEntreprises()
    {
        return $this->entreprises;
    }

    /**
     * Set entreprise>
     *
     * @param  Collection<int,  $entreprises  Entreprise>
     *
     * @return  self
     */ 
    public function setEntreprises(Collection $entreprises)
    {
        $this->entreprises = $entreprises;

        return $this;
    }
}
