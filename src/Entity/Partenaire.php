<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\PartenaireRepository;
use Doctrine\Common\Collections\Collection;
use App\Entity\Traits\CalculatedIndicatorsTrait;
use Symfony\Component\Serializer\Annotation\Groups;
use Doctrine\Common\Collections\ArrayCollection;

#[ORM\Entity(repositoryClass: PartenaireRepository::class)]
class Partenaire
{
    use CalculatedIndicatorsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $adressePhysique = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $telephone = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $email = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?float $part = null;

    #[ORM\ManyToOne(inversedBy: 'partenaires')]
    #[Groups(['list:read'])]
    private ?Entreprise $entreprise = null;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'partenaire', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['list:read'])]
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

    /**
     * @var Collection<int, Note>
     */
    #[ORM\OneToMany(targetEntity: Note::class, mappedBy: 'partenaire')]
    private Collection $notes;

    /**
     * @var Collection<int, Client>
     */
    #[ORM\ManyToMany(targetEntity: Client::class, mappedBy: 'partenaires')]
    private Collection $clients;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $idnat = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $numimpot = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $rccm = null;

    // Attributs calculés
    #[Groups(['list:read'])]
    public ?int $nombrePistesApportees;

    #[Groups(['list:read'])]
    public ?int $nombreClientsAssocies;

    #[Groups(['list:read'])]
    public ?int $nombrePolicesGenerees;

    #[Groups(['list:read'])]
    public ?int $nombreConditionsPartage;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->conditionPartages = new ArrayCollection();
        $this->pistes = new ArrayCollection();
        $this->notes = new ArrayCollection();
        $this->clients = new ArrayCollection();
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
        if (count($this->conditionPartages) != 0) {
            return $this->nom;
        } else {
            return $this->nom . " (" . $this->part * 100 . "%)";
        }
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

    /**
     * @return Collection<int, Note>
     */
    public function getNotes(): Collection
    {
        return $this->notes;
    }

    public function addNote(Note $note): static
    {
        if (!$this->notes->contains($note)) {
            $this->notes->add($note);
            $note->setPartenaire($this);
        }

        return $this;
    }

    public function removeNote(Note $note): static
    {
        if ($this->notes->removeElement($note)) {
            // set the owning side to null (unless already changed)
            if ($note->getPartenaire() === $this) {
                $note->setPartenaire(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Client>
     */
    public function getClients(): Collection
    {
        return $this->clients;
    }

    public function addClient(Client $client): static
    {
        if (!$this->clients->contains($client)) {
            $this->clients->add($client);
            $client->addPartenaire($this);
        }

        return $this;
    }

    public function removeClient(Client $client): static
    {
        if ($this->clients->removeElement($client)) {
            $client->removePartenaire($this);
        }

        return $this;
    }

    public function getIdnat(): ?string
    {
        return $this->idnat;
    }

    public function setIdnat(?string $idnat): static
    {
        $this->idnat = $idnat;

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

    public function getRccm(): ?string
    {
        return $this->rccm;
    }

    public function setRccm(?string $rccm): static
    {
        $this->rccm = $rccm;

        return $this;
    }
}
