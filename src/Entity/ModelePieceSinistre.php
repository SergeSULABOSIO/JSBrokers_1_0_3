<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\ModelePieceSinistreRepository;
use Doctrine\Common\Collections\Collection;
use App\Entity\Traits\CalculatedIndicatorsTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ModelePieceSinistreRepository::class)]
class ModelePieceSinistre
{
    use CalculatedIndicatorsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?bool $obligatoire = false;

    /**
     * @var Collection<int, PieceSinistre>
     */
    #[ORM\OneToMany(mappedBy: 'type', targetEntity: PieceSinistre::class)]
    private Collection $pieceSinistres;

    #[ORM\ManyToOne(inversedBy: 'modelePieceSinistres')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Entreprise $entreprise = null;

    // Attributs calculés
    #[Groups(['list:read'])]
    public ?int $nombreUtilisations;

    #[Groups(['list:read'])]
    public ?string $statutObligation;

    public function __construct()
    {
        $this->pieceSinistres = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function isObligatoire(): ?bool
    {
        return $this->obligatoire;
    }

    public function setObligatoire(bool $obligatoire): static
    {
        $this->obligatoire = $obligatoire;
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

    /**
     * @return Collection<int, PieceSinistre>
     */
    public function getPieceSinistres(): Collection
    {
        return $this->pieceSinistres;
    }

    public function addPieceSinistre(PieceSinistre $pieceSinistre): static
    {
        if (!$this->pieceSinistres->contains($pieceSinistre)) {
            $this->pieceSinistres->add($pieceSinistre);
            $pieceSinistre->setType($this);
        }
        return $this;
    }

    public function removePieceSinistre(PieceSinistre $pieceSinistre): static
    {
        if ($this->pieceSinistres->removeElement($pieceSinistre)) {
            // set the owning side to null (unless already changed)
            if ($pieceSinistre->getType() === $this) {
                $pieceSinistre->setType(null);
            }
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->nom ?? 'Nouveau Modèle';
    }
}