<?php

namespace App\Entity;

use App\Repository\ClasseurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use App\Entity\Traits\AuditableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: ClasseurRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Classeur
{
    use AuditableTrait;
    public const NOM_CLASSEUR_POP = "PREUVES DES PAIEMENTS";
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'classeur')]
    private Collection $documents;

    //Attributs calculés
    #[Groups(['list:read'])]
    public ?int $nombreDocuments = null;

    #[Groups(['list:read'])]
    public ?string $ageClasseur = null;

    #[Groups(['list:read'])]
    public ?\DateTimeInterface $dateDernierAjout = null;

    #[Groups(['list:read'])]
    public ?array $apercuTypesFichiers = null;

    #[Groups(['list:read'])]
    public ?string $estVide = null;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

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
            $document->setClasseur($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getClasseur() === $this) {
                $document->setClasseur(null);
            }
        }

        return $this;
    }
}
