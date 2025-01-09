<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\CompteBancaireRepository;

#[ORM\Entity(repositoryClass: CompteBancaireRepository::class)]
class CompteBancaire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $intitule = null;

    #[ORM\Column(length: 255)]
    private ?string $numero = null;

    #[ORM\Column(length: 255)]
    private ?string $banque = null;

    #[ORM\Column(length: 255)]
    private ?string $codeSwift = null;

    #[ORM\ManyToOne(inversedBy: 'compteBancaires')]
    private ?Entreprise $entreprise = null;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'compteBancaire', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    /**
     * @var Collection<int, Note>
     */
    #[ORM\ManyToMany(targetEntity: Note::class, mappedBy: 'comptes')]
    private Collection $notes;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->notes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIntitule(): ?string
    {
        return $this->intitule;
    }

    public function setIntitule(string $intitule): self
    {
        $this->intitule = $intitule;
        
        return $this;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): self
    {
        $this->numero = $numero;
        
        return $this;
    }

    public function getBanque(): ?string
    {
        return $this->banque;
    }

    public function setBanque(string $banque): self
    {
        $this->banque = $banque;
        
        return $this;
    }

    public function getCodeSwift(): ?string
    {
        return $this->codeSwift;
    }

    public function setCodeSwift(string $codeSwift): self
    {
        $this->codeSwift = $codeSwift;
        
        return $this;
    }


    public function __toString()
    {
        return $this->intitule . " - " . $this->banque . " - " . $this->numero;
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
            $document->setCompteBancaire($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getCompteBancaire() === $this) {
                $document->setCompteBancaire(null);
            }
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
            $note->addCompte($this);
        }

        return $this;
    }

    public function removeNote(Note $note): static
    {
        if ($this->notes->removeElement($note)) {
            $note->removeCompte($this);
        }

        return $this;
    }
}
