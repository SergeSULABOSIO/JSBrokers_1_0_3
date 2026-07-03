<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\FournisseurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @file Fournisseur professionnel du cabinet de courtage (workspace, module Finances).
 * @description Référentiel achats / services généraux : opérateurs économiques auprès
 * desquels le cabinet s'approvisionne (provider internet, consommables de bureau,
 * livraison de courriers…). Une DepenseCourtier peut être rattachée à un fournisseur
 * enregistré — le champ libre « bénéficiaire » restant disponible pour les remises de
 * fonds à des bénéficiaires occasionnels (personnes physiques, non-opérateurs). Porte
 * les pièces du dossier fournisseur (contrats, agréments, preuves de partenariat…)
 * via la collection de Documents. Scopé à l'entreprise (AuditableTrait).
 */
#[ORM\Entity(repositoryClass: FournisseurRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Fournisseur implements OwnerAwareInterface
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[Assert\NotBlank(message: 'Le nom du fournisseur ne peut pas être vide.')]
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    /** Personne de contact chez le fournisseur. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $personneContact = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $telephone = null;

    #[Assert\Email(message: "L'adresse e-mail n'est pas valide.")]
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $adresse = null;

    /** Registre du commerce (RCCM) — identification de l'opérateur économique. */
    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $rccm = null;

    /** Numéro d'imposition (NIF) du fournisseur. */
    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $numimpot = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    /** Fournisseur actif : proposé à la sélection lors de la saisie des dépenses. */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private bool $actif = true;

    /**
     * Dossier fournisseur : contrats, agréments, preuves de partenariat…
     *
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'fournisseur', cascade: ['persist'])]
    private Collection $documents;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
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

    public function getPersonneContact(): ?string
    {
        return $this->personneContact;
    }

    public function setPersonneContact(?string $personneContact): static
    {
        $this->personneContact = $personneContact;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getRccm(): ?string
    {
        return $this->rccm;
    }

    public function setRccm(?string $rccm): static
    {
        $this->rccm = $rccm;

        return $this;
    }

    public function getNumimpot(): ?string
    {
        return $this->numimpot;
    }

    public function setNumimpot(?string $numimpot): static
    {
        $this->numimpot = $numimpot;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    /**
     * @return Collection<int, Document>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setFournisseur($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getFournisseur() === $this) {
                $document->setFournisseur(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom ?? 'Fournisseur';
    }
}
