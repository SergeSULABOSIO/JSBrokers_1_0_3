<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\DocumentRepository;
use App\Entity\Traits\TimestampableTrait;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class Document
{
    use TimestampableTrait;
    
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[Vich\UploadableField(mapping: 'piece_sinistre_documents', fileNameProperty: 'nomFichierStocke')]
    private ?File $fichier = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomFichierStocke = null;

    // #[ORM\Column]
    // private ?\DateTimeImmutable $createdAt = null;

    // #[ORM\Column]
    // private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Classeur $classeur = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?PieceSinistre $pieceSinistre = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?OffreIndemnisationSinistre $offreIndemnisationSinistre = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Cotation $cotation = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Avenant $avenant = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Tache $tache = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Feedback $feedback = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Client $client = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Bordereau $bordereau = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?CompteBancaire $compteBancaire = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Piste $piste = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Partenaire $partenaire = null;

    #[ORM\ManyToOne(inversedBy: 'preuves')]
    private ?Paiement $paiement = null;

    public function setFichier(?File $fichier = null): void
    {
        $this->fichier = $fichier;
        if (null !== $fichier) {
            // Il faut mettre à jour updatedAt pour que le bundle sache qu'il y a eu un changement
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getFichier(): ?File
    {
        return $this->fichier;
    }

    public function setNomFichierStocke(?string $nomFichierStocke): void
    {
        $this->nomFichierStocke = $nomFichierStocke;
    }

    public function getNomFichierStocke(): ?string
    {
        return $this->nomFichierStocke;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    // public function getCreatedAt(): ?\DateTimeImmutable
    // {
    //     return $this->createdAt;
    // }

    // public function setCreatedAt(\DateTimeImmutable $createdAt): static
    // {
    //     $this->createdAt = $createdAt;

    //     return $this;
    // }

    // public function getUpdatedAt(): ?\DateTimeImmutable
    // {
    //     return $this->updatedAt;
    // }

    // public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    // {
    //     $this->updatedAt = $updatedAt;

    //     return $this;
    // }

    public function getClasseur(): ?Classeur
    {
        return $this->classeur;
    }

    public function setClasseur(?Classeur $classeur): static
    {
        $this->classeur = $classeur;

        return $this;
    }

    public function getPieceSinistre(): ?PieceSinistre
    {
        return $this->pieceSinistre;
    }

    public function setPieceSinistre(?PieceSinistre $pieceSinistre): static
    {
        $this->pieceSinistre = $pieceSinistre;

        return $this;
    }

    public function getOffreIndemnisationSinistre(): ?OffreIndemnisationSinistre
    {
        return $this->offreIndemnisationSinistre;
    }

    public function setOffreIndemnisationSinistre(?OffreIndemnisationSinistre $offreIndemnisationSinistre): static
    {
        $this->offreIndemnisationSinistre = $offreIndemnisationSinistre;

        return $this;
    }

    // public function getPaiement(): ?Paiement
    // {
    //     return $this->paiement;
    // }

    // public function setPaiement(?Paiement $paiement): static
    // {
    //     $this->paiement = $paiement;

    //     return $this;
    // }

    public function getCotation(): ?Cotation
    {
        return $this->cotation;
    }

    public function setCotation(?Cotation $cotation): static
    {
        $this->cotation = $cotation;

        return $this;
    }

    public function getAvenant(): ?Avenant
    {
        return $this->avenant;
    }

    public function setAvenant(?Avenant $avenant): static
    {
        $this->avenant = $avenant;

        return $this;
    }

    public function getTache(): ?Tache
    {
        return $this->tache;
    }

    public function setTache(?Tache $tache): static
    {
        $this->tache = $tache;

        return $this;
    }

    public function getFeedback(): ?Feedback
    {
        return $this->feedback;
    }

    public function setFeedback(?Feedback $feedback): static
    {
        $this->feedback = $feedback;

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

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

    public function getCompteBancaire(): ?CompteBancaire
    {
        return $this->compteBancaire;
    }

    public function setCompteBancaire(?CompteBancaire $compteBancaire): static
    {
        $this->compteBancaire = $compteBancaire;

        return $this;
    }

    public function getPiste(): ?Piste
    {
        return $this->piste;
    }

    public function setPiste(?Piste $piste): static
    {
        $this->piste = $piste;

        return $this;
    }

    public function getPartenaire(): ?Partenaire
    {
        return $this->partenaire;
    }

    public function setPartenaire(?Partenaire $partenaire): static
    {
        $this->partenaire = $partenaire;

        return $this;
    }

    public function getPaiement(): ?Paiement
    {
        return $this->paiement;
    }

    public function setPaiement(?Paiement $paiement): static
    {
        $this->paiement = $paiement;

        return $this;
    }
}
