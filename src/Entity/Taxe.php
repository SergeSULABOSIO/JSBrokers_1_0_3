<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Entity\Entreprise;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\TaxeRepository;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * ⚠ UN TAXE N'EXISTE QU'UNE FOIS PAR CABINET.
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
#[ORM\Entity(repositoryClass: TaxeRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_taxe_entreprise_cle', columns: ['entreprise_id', 'code'])]
class Taxe
{
    use AuditableTrait;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[Assert\NotBlank(message: "Le code ne peut pas être vide.")]
    #[ORM\Column(length: 50)]
    #[Groups(['list:read'])]
    private ?string $code = null;

    #[Assert\NotBlank(message: "Ce champ ne peut pas être vide.")]
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    #[Assert\NotBlank(message: "Ce champ ne peut pas être vide.")]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Groups(['list:read'])]
    private ?string $tauxIARD = null;

    #[Assert\NotBlank(message: "Ce champ ne peut pas être vide.")]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Groups(['list:read'])]
    private ?string $tauxVIE = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?int $redevable = null;

    public const REDEVABLE_COURTIER = 0;
    public const REDEVABLE_ASSUREUR = 1;

    /**
     * @var Collection<int, AutoriteFiscale>
     */
    #[ORM\OneToMany(targetEntity: AutoriteFiscale::class, mappedBy: 'taxe')]
    private Collection $autoriteFiscales;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->autoriteFiscales = new ArrayCollection();
    }

    /**
     * @var Collection<int, Document> Pièces jointes de cette fiche.
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'taxe', cascade: ['persist', 'remove'], orphanRemoval: true)]
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
            $document->setTaxe($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getTaxe() === $this) {
                $document->setTaxe(null);
            }
        }

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
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

    public function getTauxIARD(): ?string
    {
        return $this->tauxIARD;
    }

    public function setTauxIARD(string $tauxIARD): static
    {
        $this->tauxIARD = $tauxIARD;
        return $this;
    }

    public function getTauxVIE(): ?string
    {
        return $this->tauxVIE;
    }

    public function setTauxVIE(string $tauxVIE): static
    {
        $this->tauxVIE = $tauxVIE;
        return $this;
    }

    /**
     * Taux de la taxe en tant que Pourcentage (source unique de la convention :
     * le taux est stocké en POURCENTAGE ENTIER — 16 = 16 %). Calcul et affichage
     * doivent passer par ce VO, jamais par ×100 / ÷100 à la main.
     */
    public function tauxPourcentage(bool $isIARD): \App\Util\Pourcentage
    {
        return \App\Util\Pourcentage::fromPourcent($isIARD ? $this->tauxIARD : $this->tauxVIE);
    }

    public function getRedevable(): ?int
    {
        return $this->redevable;
    }

    public function setRedevable(?int $redevable): static
    {
        $this->redevable = $redevable;
        return $this;
    }

    /**
     * @return Collection<int, AutoriteFiscale>
     */
    public function getAutoriteFiscales(): Collection
    {
        return $this->autoriteFiscales;
    }

    public function addAutoriteFiscale(AutoriteFiscale $autoriteFiscale): static
    {
        if (!$this->autoriteFiscales->contains($autoriteFiscale)) {
            $this->autoriteFiscales->add($autoriteFiscale);
            $autoriteFiscale->setTaxe($this);
        }
        return $this;
    }

    public function removeAutoriteFiscale(AutoriteFiscale $autoriteFiscale): static
    {
        if ($this->autoriteFiscales->removeElement($autoriteFiscale)) {
            if ($autoriteFiscale->getTaxe() === $this) {
                $autoriteFiscale->setTaxe(null);
            }
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->code ?? 'Taxe sans code';
    }
}