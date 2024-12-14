<?php

namespace App\Entity;

use App\Repository\PisteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PisteRepository::class)]
class Piste
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;
    
    #[ORM\ManyToOne(inversedBy: 'pistes')]
    private ?Risque $risque = null;

    #[ORM\ManyToOne(inversedBy: 'pistes')]
    private ?Client $client = null;

    #[ORM\ManyToOne(inversedBy: 'pistes')]
    private ?Invite $invite = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referencePolice = null;

    #[ORM\Column(nullable: true)]
    private ?float $primePotentielle = null;

    #[ORM\Column(nullable: true)]
    private ?float $commissionPotentielle = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, Tache>
     */
    #[ORM\OneToMany(targetEntity: Tache::class, mappedBy: 'piste')]
    private Collection $taches;

    public function __construct()
    {
        $this->taches = new ArrayCollection();
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

    public function getPrimePotentielle(): ?float
    {
        return $this->primePotentielle;
    }

    public function setPrimePotentielle(?float $primePotentielle): static
    {
        $this->primePotentielle = $primePotentielle;

        return $this;
    }

    public function getCommissionPotentielle(): ?float
    {
        return $this->commissionPotentielle;
    }

    public function setCommissionPotentielle(?float $commissionPotentielle): static
    {
        $this->commissionPotentielle = $commissionPotentielle;

        return $this;
    }

    public function getRisque(): ?Risque
    {
        return $this->risque;
    }

    public function setRisque(?Risque $risque): static
    {
        $this->risque = $risque;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

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

    public function __toString(): string
    {
        return $this->nom;
    }

    public function getReferencePolice(): ?string
    {
        return $this->referencePolice;
    }

    public function setReferencePolice(?string $referencePolice): static
    {
        $this->referencePolice = $referencePolice;

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

    /**
     * @return Collection<int, Tache>
     */
    public function getTaches(): Collection
    {
        return $this->taches;
    }

    public function addTach(Tache $tach): static
    {
        if (!$this->taches->contains($tach)) {
            $this->taches->add($tach);
            $tach->setPiste($this);
        }

        return $this;
    }

    public function removeTach(Tache $tach): static
    {
        if ($this->taches->removeElement($tach)) {
            // set the owning side to null (unless already changed)
            if ($tach->getPiste() === $this) {
                $tach->setPiste(null);
            }
        }

        return $this;
    }
}
