<?php

namespace App\Entity;

use App\Repository\AssureurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssureurRepository::class)]
class Assureur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(length: 255)]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adressePhysique = null;

    #[ORM\ManyToOne(inversedBy: 'assureurs')]
    private ?Entreprise $entreprise = null;

    /**
     * @var Collection<int, Cotation>
     */
    #[ORM\OneToMany(targetEntity: Cotation::class, mappedBy: 'assureur')]
    private Collection $cotations;

    /**
     * @var Collection<int, Bordereau>
     */
    #[ORM\OneToMany(targetEntity: Bordereau::class, mappedBy: 'assureur')]
    private Collection $bordereaus;

    /**
     * @var Collection<int, Note>
     */
    #[ORM\OneToMany(targetEntity: Note::class, mappedBy: 'assureur')]
    private Collection $notes;

    public function __construct()
    {
        $this->cotations = new ArrayCollection();
        $this->bordereaus = new ArrayCollection();
        $this->notes = new ArrayCollection();
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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

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
     * @return Collection<int, Cotation>
     */
    public function getCotations(): Collection
    {
        return $this->cotations;
    }

    public function addCotation(Cotation $cotation): static
    {
        if (!$this->cotations->contains($cotation)) {
            $this->cotations->add($cotation);
            $cotation->setAssureur($this);
        }

        return $this;
    }

    public function removeCotation(Cotation $cotation): static
    {
        if ($this->cotations->removeElement($cotation)) {
            // set the owning side to null (unless already changed)
            if ($cotation->getAssureur() === $this) {
                $cotation->setAssureur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Bordereau>
     */
    public function getBordereaus(): Collection
    {
        return $this->bordereaus;
    }

    public function addBordereau(Bordereau $bordereau): static
    {
        if (!$this->bordereaus->contains($bordereau)) {
            $this->bordereaus->add($bordereau);
            $bordereau->setAssureur($this);
        }

        return $this;
    }

    public function removeBordereau(Bordereau $bordereau): static
    {
        if ($this->bordereaus->removeElement($bordereau)) {
            // set the owning side to null (unless already changed)
            if ($bordereau->getAssureur() === $this) {
                $bordereau->setAssureur(null);
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
            $note->setAssureur($this);
        }

        return $this;
    }

    public function removeNote(Note $note): static
    {
        if ($this->notes->removeElement($note)) {
            // set the owning side to null (unless already changed)
            if ($note->getAssureur() === $this) {
                $note->setAssureur(null);
            }
        }

        return $this;
    }
}
