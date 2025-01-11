<?php

namespace App\Entity;

use App\Repository\NoteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteRepository::class)]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    /**
     * @var Collection<int, Article>
     */
    #[ORM\OneToMany(targetEntity: Article::class, mappedBy: 'note', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $articles;

    #[ORM\Column]
    private ?int $type = null;
    public const TYPE_NULL = -1;
    public const TYPE_NOTE_DE_DEBIT = 0;
    public const TYPE_NOTE_DE_CREDIT = 1;

    #[ORM\Column]
    private ?int $addressedTo = null;
    public const TO_NULL = -1;
    public const TO_CLIENT = 0;
    public const TO_ASSUREUR = 1;
    public const TO_PARTENAIRE = 2;

    #[ORM\ManyToOne(inversedBy: 'notes')]
    private ?Invite $invite = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'notes')]
    private ?Client $client = null;

    #[ORM\ManyToOne(inversedBy: 'notes')]
    private ?Partenaire $partenaire = null;

    #[ORM\ManyToOne(inversedBy: 'notes')]
    private ?Assureur $assureur = null;

    /**
     * @var Collection<int, CompteBancaire>
     */
    #[ORM\ManyToMany(targetEntity: CompteBancaire::class, inversedBy: 'notes')]
    private Collection $comptes;

    /**
     * @var Collection<int, Paiement>
     */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'note')]
    private Collection $paiements;

    #[ORM\Column(length: 255)]
    private ?string $reference = null;

    public function __construct()
    {
        $this->articles = new ArrayCollection();
        $this->comptes = new ArrayCollection();
        $this->paiements = new ArrayCollection();
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

    /**
     * @return Collection<int, Article>
     */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    public function addArticle(Article $article): static
    {
        if (!$this->articles->contains($article)) {
            $this->articles->add($article);
            $article->setNote($this);
        }

        return $this;
    }

    public function removeArticle(Article $article): static
    {
        if ($this->articles->removeElement($article)) {
            // set the owning side to null (unless already changed)
            if ($article->getNote() === $this) {
                $article->setNote(null);
            }
        }

        return $this;
    }

    public function getType(): ?int
    {
        return $this->type;
    }

    public function setType(int $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getInvite(): ?Invite
    {
        return $this->invite;
    }

    public function setInvite(?Invite $invite): static
    {
        $this->invite = $invite;

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

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

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

    public function getAssureur(): ?Assureur
    {
        return $this->assureur;
    }

    public function setAssureur(?Assureur $assureur): static
    {
        $this->assureur = $assureur;

        return $this;
    }

    public function getAddressedTo(): ?int
    {
        return $this->addressedTo;
    }

    public function setAddressedTo(int $addressedTo): static
    {
        $this->addressedTo = $addressedTo;

        return $this;
    }

    /**
     * @return Collection<int, CompteBancaire>
     */
    public function getComptes(): Collection
    {
        return $this->comptes;
    }

    public function addCompte(CompteBancaire $compte): static
    {
        if (!$this->comptes->contains($compte)) {
            $this->comptes->add($compte);
        }

        return $this;
    }

    public function removeCompte(CompteBancaire $compte): static
    {
        $this->comptes->removeElement($compte);

        return $this;
    }

    /**
     * @return Collection<int, Paiement>
     */
    public function getPaiements(): Collection
    {
        return $this->paiements;
    }

    public function addPaiement(Paiement $paiement): static
    {
        if (!$this->paiements->contains($paiement)) {
            $this->paiements->add($paiement);
            $paiement->setNote($this);
        }

        return $this;
    }

    public function removePaiement(Paiement $paiement): static
    {
        if ($this->paiements->removeElement($paiement)) {
            // set the owning side to null (unless already changed)
            if ($paiement->getNote() === $this) {
                $paiement->setNote(null);
            }
        }

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }
}
