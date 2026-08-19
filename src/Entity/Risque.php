<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\RisqueRepository;
use Doctrine\Common\Collections\Collection;
use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\CalculatedIndicatorsTrait;
use Symfony\Component\Serializer\Annotation\Groups;
use Doctrine\Common\Collections\ArrayCollection;

#[ORM\Entity(repositoryClass: RisqueRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Risque
{
    use AuditableTrait;
    use CalculatedIndicatorsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $code = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $pourcentageCommissionSpecifiqueHT = null;

    // Branche dominante du portefeuille d'un courtier ; la vie reste une bascule
    // explicite. Colonne NOT NULL : sans défaut, la création échouait au flush.
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $branche = self::BRANCHE_IARD_OU_NON_VIE;
    public const BRANCHE_IARD_OU_NON_VIE = 0;
    public const BRANCHE_VIE = 1;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nomComplet = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?bool $imposable = null;

    /**
     * @var Collection<int, Piste>
     */
    #[ORM\OneToMany(targetEntity: Piste::class, mappedBy: 'risque', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['list:read'])]
    private Collection $pistes;

    /**
     * @var Collection<int, NotificationSinistre>
     */
    #[ORM\OneToMany(targetEntity: NotificationSinistre::class, mappedBy: 'risque')]
    #[Groups(['list:read'])]
    private Collection $notificationSinistres;

    /**
     * LES CONDITIONS DE PARTAGE QUI CIBLENT CE RISQUE — côté inverse, en lecture.
     *
     * Un risque est une entrée du CATALOGUE de l'entreprise (« Incendie », « RC
     * automobile »), pas la propriété d'une condition de partage. Il peut donc être visé
     * par plusieurs conditions à la fois — ce que l'ancienne relation to-one interdisait :
     * le cibler depuis une seconde condition le retirait silencieusement de la première.
     *
     * @var Collection<int, ConditionPartage>
     */
    #[ORM\ManyToMany(targetEntity: ConditionPartage::class, mappedBy: 'produits')]
    #[Groups(['list:read'])]
    private Collection $conditionsPartage;

    // NOUVEAU : Attributs calculés spécifiques (Miroir de Cotation/Partenaire)
    #[Groups(['list:read'])]
    public ?float $primeTotale = null;

    #[Groups(['list:read'])]
    public ?float $primePayee = null;

    #[Groups(['list:read'])]
    public ?float $primeSoldeDue = null;

    #[Groups(['list:read'])]
    public ?float $tauxCommission = null;

    #[Groups(['list:read'])]
    public ?float $montantHT = null;

    #[Groups(['list:read'])]
    public ?float $montantTTC = null;

    #[Groups(['list:read'])]
    public ?string $detailCalcul = null;

    #[Groups(['list:read'])]
    public ?float $taxeCourtierMontant = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurMontant = null;

    #[Groups(['list:read'])]
    public ?float $montant_du = null;

    #[Groups(['list:read'])]
    public ?float $montant_paye = null;

    #[Groups(['list:read'])]
    public ?float $solde_restant_du = null;

    #[Groups(['list:read'])]
    public ?float $taxeCourtierPayee = null;

    #[Groups(['list:read'])]
    public ?float $taxeCourtierSolde = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurPayee = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurSolde = null;

    #[Groups(['list:read'])]
    public ?float $montantPur = null;

    #[Groups(['list:read'])]
    public ?float $retroCommission = null;

    #[Groups(['list:read'])]
    public ?float $retroCommissionReversee = null;

    #[Groups(['list:read'])]
    public ?float $retroCommissionSolde = null;

    #[Groups(['list:read'])]
    public ?float $reserve = null;

    // Indicateurs Sinistralité
    #[Groups(['list:read'])]
    public ?float $indemnisationDue = null;

    #[Groups(['list:read'])]
    public ?float $indemnisationVersee = null;

    #[Groups(['list:read'])]
    public ?float $indemnisationSolde = null;

    #[Groups(['list:read'])]
    public ?float $tauxSP = null;

    #[Groups(['list:read'])]
    public ?string $tauxSPInterpretation = null;

    // Indicateurs de comptage
    #[Groups(['list:read'])]
    public ?int $nombrePistes = null;

    #[Groups(['list:read'])]
    public ?int $nombreSinistres = null;

    #[Groups(['list:read'])]
    public ?int $nombrePolices = null;
    
    #[Groups(['list:read'])]
    public ?string $brancheString = null;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->pistes = new ArrayCollection();
        $this->notificationSinistres = new ArrayCollection();
        $this->conditionsPartage = new ArrayCollection();
    }

    /**
     * @var Collection<int, Document> Pièces jointes de cette fiche.
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'risque', cascade: ['persist', 'remove'], orphanRemoval: true)]
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
            $document->setRisque($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getRisque() === $this) {
                $document->setRisque(null);
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

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPourcentageCommissionSpecifiqueHT(): ?float
    {
        return $this->pourcentageCommissionSpecifiqueHT;
    }

    /**
     * Fraction (0..1) dérivée du taux stocké en POINTS (16 = 16 %) : source UNIQUE
     * pour tout calcul monétaire (assiette × fraction). Ne jamais multiplier une
     * assiette par getPourcentageCommissionSpecifiqueHT() directement.
     */
    public function getFraction(): float
    {
        return ($this->pourcentageCommissionSpecifiqueHT ?? 0.0) / 100.0;
    }

    public function setPourcentageCommissionSpecifiqueHT(?float $pourcentageCommissionSpecifiqueHT): static
    {
        $this->pourcentageCommissionSpecifiqueHT = $pourcentageCommissionSpecifiqueHT;

        return $this;
    }

    public function getBranche(): ?int
    {
        return $this->branche;
    }

    public function setBranche(int $branche): static
    {
        $this->branche = $branche;

        return $this;
    }

    public function getNomComplet(): ?string
    {
        return $this->nomComplet;
    }

    public function setNomComplet(string $nomComplet): static
    {
        $this->nomComplet = $nomComplet;

        return $this;
    }

    public function isImposable(): ?bool
    {
        return $this->imposable;
    }

    public function setImposable(bool $imposable): static
    {
        $this->imposable = $imposable;

        return $this;
    }

    /**
     * @return Collection<int, Piste>
     */
    public function getPistes(): Collection
    {
        return $this->pistes;
    }

    public function addPiste(Piste $piste): static
    {
        if (!$this->pistes->contains($piste)) {
            $this->pistes->add($piste);
            $piste->setRisque($this);
        }

        return $this;
    }

    public function removePiste(Piste $piste): static
    {
        if ($this->pistes->removeElement($piste)) {
            // set the owning side to null (unless already changed)
            if ($piste->getRisque() === $this) {
                $piste->setRisque(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->nomComplet;
    }

    /**
     * @return Collection<int, NotificationSinistre>
     */
    public function getNotificationSinistres(): Collection
    {
        return $this->notificationSinistres;
    }

    public function addNotificationSinistre(NotificationSinistre $notificationSinistre): static
    {
        if (!$this->notificationSinistres->contains($notificationSinistre)) {
            $this->notificationSinistres->add($notificationSinistre);
            $notificationSinistre->setRisque($this);
        }

        return $this;
    }

    public function removeNotificationSinistre(NotificationSinistre $notificationSinistre): static
    {
        if ($this->notificationSinistres->removeElement($notificationSinistre)) {
            // set the owning side to null (unless already changed)
            if ($notificationSinistre->getRisque() === $this) {
                $notificationSinistre->setRisque(null);
            }
        }

        return $this;
    }

    /**
     * Les conditions de partage qui ciblent ce risque.
     *
     * Lecture seule, volontairement : le rattachement se pose depuis la CONDITION
     * (`ConditionPartage::addProduit()`), qui est le côté propriétaire. Offrir ici un
     * setter donnerait deux chemins pour écrire la même chose, dont un qui ne mettrait
     * pas la table de liaison à jour.
     *
     * @return Collection<int, ConditionPartage>
     */
    public function getConditionsPartage(): Collection
    {
        return $this->conditionsPartage;
    }
}
