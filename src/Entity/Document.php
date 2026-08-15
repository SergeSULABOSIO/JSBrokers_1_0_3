<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\DocumentRepository;
use App\Entity\Traits\AuditableTrait;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Annotation\Groups;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_document_cible', columns: ['cible_type', 'cible_id'])]
#[Vich\Uploadable]
class Document
{
    use AuditableTrait;
    
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    #[Vich\UploadableField(mapping: 'piece_sinistre_documents', fileNameProperty: 'nomFichierStocke')]
    private ?File $fichier = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $nomFichierStocke = null;

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

    // Preuve d'un signalement de paiement de prime (déclaratif, hors trésorerie).
    #[ORM\ManyToOne(inversedBy: 'preuves')]
    private ?PaiementPrime $paiementPrime = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    private ?Fournisseur $fournisseur = null;

    /**
     * RATTACHEMENT UNIVERSEL — le nom court de l'entité à laquelle ce document est
     * rattaché quand AUCUNE des relations ci-dessus ne la couvre (« Tranche »,
     * « Note », « Assureur »…).
     *
     * POURQUOI DEUX COLONNES ET NON QUINZE DE PLUS. Quinze colonnes de parent
     * disaient déjà « un document a une origine, pas quatorze » — mais elles ne le
     * disaient que pour quinze entités sur soixante-dix-sept. Partout ailleurs, le
     * serveur devait avertir que le fichier NE SERAIT PAS CONSERVÉ : la donnée
     * extraite entrait en base, la pièce qui la justifiait mourait avec la
     * conversation. Ajouter une colonne par entité aurait déplacé la limite sans la
     * supprimer, et fait grossir cette table à chaque entité nouvelle.
     *
     * ⚠️ CE COUPLE EST UN DERNIER RECOURS, PAS UNE ALTERNATIVE. Quand une relation
     * typée existe pour la cible, c'est ELLE qui est écrite et ce couple reste nul —
     * sinon la rubrique Documents et Ket liraient deux vérités différentes du même
     * rattachement. La règle est centralisée dans PieceSourceRattachement, et
     * DocumentFichier::parentDe() lit les deux mécanismes dans cet ordre.
     *
     * Il n'y a PAS de clé étrangère derrière : la suppression du parent est prise en
     * charge par DocumentsOrphelinsSubscriber, faute de quoi ces lignes survivraient
     * à l'objet qu'elles décrivent.
     */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $cibleType = null;

    #[ORM\Column(nullable: true)]
    private ?int $cibleId = null;

    #[Groups(['list:read'])]
    public ?string $parent_string;

    #[Groups(['list:read'])]
    public ?string $classeur_string;

    #[Groups(['list:read'])]
    public ?string $ageDocument;

    #[Groups(['list:read'])]
    public ?string $typeFichier;

    #[Groups(['list:read'])]
    public ?string $tailleFichier;

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

    public function getPaiementPrime(): ?PaiementPrime
    {
        return $this->paiementPrime;
    }

    public function setPaiementPrime(?PaiementPrime $paiementPrime): static
    {
        $this->paiementPrime = $paiementPrime;

        return $this;
    }

    public function getFournisseur(): ?Fournisseur
    {
        return $this->fournisseur;
    }

    public function setFournisseur(?Fournisseur $fournisseur): static
    {
        $this->fournisseur = $fournisseur;

        return $this;
    }

    public function getCibleType(): ?string
    {
        return $this->cibleType;
    }

    public function setCibleType(?string $cibleType): static
    {
        // Une chaîne vide arrive du formulaire quand le champ caché n'est pas
        // renseigné ; la laisser passer produirait un rattachement vers une entité
        // nommée « », c'est-à-dire un parent que parentDe() chercherait à résoudre à
        // chaque ligne de liste.
        $this->cibleType = ($cibleType === null || trim($cibleType) === '') ? null : trim($cibleType);

        return $this;
    }

    public function getCibleId(): ?int
    {
        return $this->cibleId;
    }

    public function setCibleId(?int $cibleId): static
    {
        $this->cibleId = ($cibleId !== null && $cibleId > 0) ? $cibleId : null;

        return $this;
    }

    /** Le couple de rattachement universel est-il complet ? (les deux moitiés, ou rien) */
    public function aUneCibleUniverselle(): bool
    {
        return $this->cibleType !== null && $this->cibleId !== null;
    }

    public function __toString(): string
    {
        return $this->nom ?? 'Nouveau document';
    }
}
