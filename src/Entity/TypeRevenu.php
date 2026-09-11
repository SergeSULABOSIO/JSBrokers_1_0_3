<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\TypeRevenuRepository;
use App\Entity\Traits\AuditableTrait;
use Doctrine\Common\Collections\{ArrayCollection, Collection};
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * ⚠ UN TYPE DE REVENU N'EXISTE QU'UNE FOIS PAR CABINET.
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
#[ORM\Entity(repositoryClass: TypeRevenuRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_type_revenu_entreprise_cle', columns: ['entreprise_id', 'nom'])]
class TypeRevenu
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

    // #[ORM\Column(nullable: true)]
    // private ?int $formule = null;

    public const MODE_CALCUL_POURCENTAGE_CHARGEMENT = 0;
    public const MODE_CALCUL_MONTANT_FLAT = 1;

    public const SYSTEM_ADJUSTMENT_REVENU_NAME = '[Ajustement système - Écart commission]';

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $montantflat = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?bool $shared = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?bool $multipayments = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $redevable = null;

    public const REDEVABLE_CLIENT = 0;
    public const REDEVABLE_ASSUREUR = 1;
    public const REDEVABLE_REASSURER = 2;
    public const REDEVABLE_PARTENAIRE = 3;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $pourcentage = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?bool $appliquerPourcentageDuRisque = null;

    /**
     * @var Collection<int, RevenuPourCourtier>
     */
    #[ORM\OneToMany(targetEntity: RevenuPourCourtier::class, mappedBy: 'typeRevenu')]
    private Collection $revenuPourCourtiers;

    #[ORM\ManyToOne(inversedBy: 'typeRevenus')]
    #[Groups(['list:read'])]
    private ?Chargement $typeChargement = null;

    /**
     * ⚠ DEUX LIENS SANS CÔTÉ INVERSE, ET C'EST VOULU.
     *
     * Ils annonçaient `inversedBy: 'revenus'` et `inversedBy: 'typeRevenu'` — deux champs
     * qui n'ont jamais existé sur `Note` ni sur `Article`. `doctrine:schema:validate` le
     * signalait, et le moteur de suppression en chaîne, qui lit les métadonnées pour
     * savoir ce qui appartient à quoi, y voyait une collection à suivre.
     *
     * ⚠ ET ON NE CRÉE SURTOUT PAS CES COLLECTIONS. Un type de revenu est un élément du
     * CATALOGUE du cabinet : le déclarer enfant d'une facture le ferait détruire avec
     * elle. Ces colonnes sont nullables, le lien se coupe donc tout seul.
     */
    #[ORM\ManyToOne]
    #[Groups(['list:read'])]
    private ?Note $note = null;

    #[ORM\ManyToOne]
    #[Groups(['list:read'])]
    private ?Article $article = null;

    // /**
    //  * @var Collection<int, Article>
    //  */
    // #[ORM\ManyToMany(targetEntity: Article::class, mappedBy: 'revenus')]
    // private Collection $articles;

    // Le taux du type appliqué à un chargement est la règle ; le montant forfaitaire est
    // l'exception qu'on choisit. `redevable`, en revanche, reste sans défaut : il dit
    // QUI paie, ce qui ne se devine pas (cf. CHOIX_METIER_REQUIS).
    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?int $modeCalcul = self::MODE_CALCUL_POURCENTAGE_CHARGEMENT;

    #[Groups(['list:read'])]
    public ?string $descriptionModeCalcul = null;

    #[Groups(['list:read'])]
    public ?string $redevableString = null;

    #[Groups(['list:read'])]
    public ?string $sharedString = null;

    /**
     * @var int|null
     */
    #[Groups(['list:read'])]
    public ?int $nombreUtilisations = null;

    #[Groups(['list:read'])]
    public ?float $pourcentageDisplay = null;


    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->revenuPourCourtiers = new ArrayCollection();
        $this->modeCalcul = self::MODE_CALCUL_POURCENTAGE_CHARGEMENT;
        $this->multipayments = true; // Définir une valeur par défaut
        // $this->articles = new ArrayCollection();
    }


    /**
     * @var Collection<int, Document> Pièces jointes de cette fiche.
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'typeRevenu', cascade: ['persist', 'remove'], orphanRemoval: true)]
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
            $document->setTypeRevenu($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getTypeRevenu() === $this) {
                $document->setTypeRevenu(null);
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

    // public function getFormule(): ?int
    // {
    //     return $this->formule;
    // }

    // public function setFormule(int $formule): static
    // {
    //     $this->formule = $formule;

    //     return $this;
    // }

    public function getMontantflat(): ?float
    {
        return $this->montantflat;
    }

    public function setMontantflat(?float $montantflat): static
    {
        $this->montantflat = $montantflat;

        return $this;
    }

    public function isShared(): ?bool
    {
        return $this->shared;
    }

    public function setShared(bool $shared): static
    {
        $this->shared = $shared;

        return $this;
    }

    public function isMultipayments(): ?bool
    {
        return $this->multipayments;
    }

    public function setMultipayments(bool $multipayments): static
    {
        $this->multipayments = $multipayments;

        return $this;
    }

    public function getRedevable(): ?int
    {
        return $this->redevable;
    }

    public function setRedevable(int $redevable): static
    {
        $this->redevable = $redevable;

        return $this;
    }

    public function getPourcentage(): ?float
    {
        return $this->pourcentage;
    }

    /**
     * Fraction (0..1) dérivée du taux stocké en POINTS (5 = 5 %) : source UNIQUE
     * pour tout calcul (chargement × fraction). Ne jamais multiplier une assiette
     * par getPourcentage() directement.
     */
    public function getFraction(): float
    {
        return ($this->pourcentage ?? 0.0) / 100.0;
    }

    public function setPourcentage(?float $pourcentage): static
    {
        $this->pourcentage = $pourcentage;

        return $this;
    }

    public function isAppliquerPourcentageDuRisque(): ?bool
    {
        return $this->appliquerPourcentageDuRisque;
    }

    public function setAppliquerPourcentageDuRisque(?bool $appliquerPourcentageDuRisque): static
    {
        $this->appliquerPourcentageDuRisque = $appliquerPourcentageDuRisque;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom;
    }

    /**
     * @return Collection<int, RevenuPourCourtier>
     */
    public function getRevenuPourCourtiers(): Collection
    {
        return $this->revenuPourCourtiers;
    }

    public function addRevenuPourCourtier(RevenuPourCourtier $revenuPourCourtier): static
    {
        if (!$this->revenuPourCourtiers->contains($revenuPourCourtier)) {
            $this->revenuPourCourtiers->add($revenuPourCourtier);
            $revenuPourCourtier->setTypeRevenu($this);
        }

        return $this;
    }

    public function removeRevenuPourCourtier(RevenuPourCourtier $revenuPourCourtier): static
    {
        if ($this->revenuPourCourtiers->removeElement($revenuPourCourtier)) {
            // set the owning side to null (unless already changed)
            if ($revenuPourCourtier->getTypeRevenu() === $this) {
                $revenuPourCourtier->setTypeRevenu(null);
            }
        }

        return $this;
    }

    public function getTypeChargement(): ?Chargement
    {
        return $this->typeChargement;
    }

    public function setTypeChargement(?Chargement $typeChargement): static
    {
        $this->typeChargement = $typeChargement;

        return $this;
    }

    public function getNote(): ?Note
    {
        return $this->note;
    }

    public function setNote(?Note $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getArticle(): ?Article
    {
        return $this->article;
    }

    public function setArticle(?Article $article): static
    {
        $this->article = $article;

        return $this;
    }

    // /**
    //  * @return Collection<int, Article>
    //  */
    // public function getArticles(): Collection
    // {
    //     return $this->articles;
    // }

    // public function addArticle(Article $article): static
    // {
    //     if (!$this->articles->contains($article)) {
    //         $this->articles->add($article);
    //         $article->addRevenu($this);
    //     }

    //     return $this;
    // }

    // public function removeArticle(Article $article): static
    // {
    //     if ($this->articles->removeElement($article)) {
    //         $article->removeRevenu($this);
    //     }

    //     return $this;
    // }

    public function getModeCalcul(): ?int
    {
        return $this->modeCalcul;
    }

    public function setModeCalcul(?int $modeCalcul): static
    {
        $this->modeCalcul = $modeCalcul;

        return $this;
    }
}
