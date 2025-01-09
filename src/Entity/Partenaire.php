<?php

namespace App\Entity;

use App\Repository\PartenaireRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PartenaireRepository::class)]
class Partenaire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adressePhysique = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column]
    private ?float $part = null;

    #[ORM\ManyToOne(inversedBy: 'partenaires')]
    private ?Entreprise $entreprise = null;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'partenaire', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    /**
     * @var Collection<int, ConditionPartage>
     */
    #[ORM\OneToMany(targetEntity: ConditionPartage::class, mappedBy: 'partenaire', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $conditionPartages;

    /**
     * @var Collection<int, Piste>
     */
    #[ORM\ManyToMany(targetEntity: Piste::class, mappedBy: 'partenaires')]
    private Collection $pistes;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->conditionPartages = new ArrayCollection();
        $this->pistes = new ArrayCollection();
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

    public function getAdressePhysique(): ?string
    {
        return $this->adressePhysique;
    }

    public function setAdressePhysique(?string $adressePhysique): static
    {
        $this->adressePhysique = $adressePhysique;

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

    public function getPart(): ?float
    {
        return $this->part;
    }

    public function setPart(float $part): static
    {
        $this->part = $part;

        return $this;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): static
    {
        $this->entreprise = $entreprise;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom;
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
            $document->setPartenaire($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getPartenaire() === $this) {
                $document->setPartenaire(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ConditionPartage>
     */
    public function getConditionPartages(): Collection
    {
        return $this->conditionPartages;
    }

    public function addConditionPartage(ConditionPartage $conditionPartage): static
    {
        if (!$this->conditionPartages->contains($conditionPartage)) {
            $this->conditionPartages->add($conditionPartage);
            $conditionPartage->setPartenaire($this);
        }

        return $this;
    }

    public function removeConditionPartage(ConditionPartage $conditionPartage): static
    {
        if ($this->conditionPartages->removeElement($conditionPartage)) {
            // set the owning side to null (unless already changed)
            if ($conditionPartage->getPartenaire() === $this) {
                $conditionPartage->setPartenaire(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Piste>
     */
    public function getPistes(): Collection
    {
        return $this->pistes;
    }

    public function addPiste(Piste $piste): static
    {
        if (!$this->pistes->contains($piste)) {
            $this->pistes->add($piste);
            $piste->addPartenaire($this);
        }

        return $this;
    }

    public function removePiste(Piste $piste): static
    {
        if ($this->pistes->removeElement($piste)) {
            $piste->removePartenaire($this);
        }

        return $this;
    }
}
