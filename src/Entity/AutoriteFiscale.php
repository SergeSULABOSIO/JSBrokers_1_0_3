<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use Symfony\Component\Serializer\Annotation\Groups;
use App\Repository\AutoriteFiscaleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * ⚠ UN AUTORITÉ FISCALE N'EXISTE QU'UNE FOIS PAR CABINET.
 *
 * Le catalogue en portait jusqu'à SIX exemplaires identiques : `ServiceInitialisation-
 * Entreprise` semait sans regarder si le poste existait, et `app:conges:provisionner`
 * rejouait ce semis en entier à chaque exécution. L'utilisateur voyait alors six lignes
 * qui se ressemblent, sans pouvoir savoir laquelle sa police employait ni laquelle
 * modifier le jour où le taux change.
 *
 * Le semis est devenu idempotent, mais une règle qui ne vit que dans du PHP est une règle
 * qu'un import, un script de reprise ou une seconde route peut contourner sans le savoir.
 * Elle est donc posée LÀ OÙ ELLE NE SE CONTOURNE PAS.
 *
 * ⚠ ET ELLE EST INSENSIBLE À LA CASSE, par la collation de la colonne — ce qui est voulu :
 * « Écart » et « écart » désignent le même poste, et deux lignes qui ne se distinguent que
 * par une majuscule sont un doublon pour tout le monde sauf pour la machine.
 */
#[ORM\Entity(repositoryClass: AutoriteFiscaleRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_autorite_fiscale_entreprise_cle', columns: ['entreprise_id', 'abreviation'])]
class AutoriteFiscale
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

    #[ORM\Column(length: 10)]
    #[Groups(['list:read'])]
    private ?string $abreviation = null;

    #[ORM\ManyToOne(inversedBy: 'autoriteFiscales')]
    private ?Taxe $taxe = null;

    /**
     * @var Collection<int, Note>
     */
    #[ORM\OneToMany(targetEntity: Note::class, mappedBy: 'autoritefiscale')]
    private Collection $notes;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->notes = new ArrayCollection();
    }


    /**
     * @var Collection<int, Document> Pièces jointes de cette fiche.
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'autoriteFiscale', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

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
            $document->setAutoriteFiscale($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getAutoriteFiscale() === $this) {
                $document->setAutoriteFiscale(null);
            }
        }

        return $this;
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

    public function getAbreviation(): ?string
    {
        return $this->abreviation;
    }

    public function setAbreviation(string $abreviation): static
    {
        $this->abreviation = $abreviation;

        return $this;
    }

    public function getTaxe(): ?Taxe
    {
        return $this->taxe;
    }

    public function setTaxe(?Taxe $taxe): static
    {
        $this->taxe = $taxe;

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
            $note->setAutoritefiscale($this);
        }

        return $this;
    }

    public function removeNote(Note $note): static
    {
        if ($this->notes->removeElement($note)) {
            // set the owning side to null (unless already changed)
            if ($note->getAutoritefiscale() === $this) {
                $note->setAutoritefiscale(null);
            }
        }

        return $this;
    }

    public function __toString()
    {
        return $this->nom . " - " . $this->abreviation;
    }
}
