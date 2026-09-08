<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Traits\AuditableTrait;
use App\Repository\MonnaieRepository;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * ⚠ UN MONNAIE N'EXISTE QU'UNE FOIS PAR CABINET.
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
#[ORM\Entity(repositoryClass: MonnaieRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_monnaie_entreprise_cle', columns: ['entreprise_id', 'code'])]
class Monnaie
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

    #[Assert\NotBlank(message: "Le code ne peut pas être vide.")]
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $code = null;

    #[Assert\NotBlank(message: "Le taux ne peut pas être vide.")]
    #[Assert\Positive(message: "Le taux doit être une valeur positive.")]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Groups(['list:read'])]
    private ?string $tauxusd = null;

    public const FONCTION_AUCUNE = -1;
    public const FONCTION_SAISIE_ET_AFFICHAGE = 0;
    public const FONCTION_SAISIE_UNIQUEMENT = 1;
    public const FONCTION_AFFICHAGE_UNIQUEMENT = 2;

    // FONCTION_AUCUNE (-1) est la valeur neutre prévue : une monnaie enregistrée n'est
    // ni de saisie ni d'affichage tant que le courtier ne l'a pas décidé. Colonne
    // NOT NULL : le vide y était une erreur au flush.
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $fonction = self::FONCTION_AUCUNE;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?bool $locale = null;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
    }

    /**
     * @var Collection<int, Document> Pièces jointes de cette fiche.
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'monnaie', cascade: ['persist', 'remove'], orphanRemoval: true)]
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
            $document->setMonnaie($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getMonnaie() === $this) {
                $document->setMonnaie(null);
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

    public function setNom(string $nom): self
    {
        $this->nom = $nom;
        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function getTauxusd(): ?string
    {
        return $this->tauxusd;
    }

    public function setTauxusd(string $tauxusd): self
    {
        $this->tauxusd = $tauxusd;
        
        return $this;
    }

    public function __toString()
    {
        return $this->code . " / " . $this->nom;
    }

    public function getFonction(): ?int
    {
        return $this->fonction;
    }

    public function setFonction(int $fonction): self
    {
        $this->fonction = $fonction;
        
        return $this;
    }

    public function isLocale(): ?bool
    {
        return $this->locale;
    }

    public function setLocale(bool $locale): static
    {
        $this->locale = $locale;

        return $this;
    }
}
