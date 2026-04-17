<?php
namespace App\Entity;


use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\CalculatedIndicatorsTrait;
use Symfony\Component\Serializer\Annotation\Groups;
use App\Repository\AvenantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AvenantRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Avenant
{
    use AuditableTrait;
    use CalculatedIndicatorsTrait;

    //Renewal status
    public const RENEWAL_STATUS_LOST        = 0;
    public const RENEWAL_STATUS_ONCE_OFF    = 1;

    // NOUVEAU : Attributs calculés spécifiques à l'avenant
    #[Groups(['list:read'])]
    public ?string $dureeCouverture = null;
    #[Groups(['list:read'])]
    public ?string $joursRestants = null;
    #[Groups(['list:read'])]
    public ?string $ageAvenant = null;
    #[Groups(['list:read'])]
    public ?string $typeAffaire = null;
    #[Groups(['list:read'])]
    public ?string $periodeCouverture = null;
    #[Groups(['list:read'])]
    public ?string $clientDescription = null;
    #[Groups(['list:read'])]
    public ?string $risqueDescription = null;
    #[Groups(['list:read'])]
    public ?string $risqueCode = null;
    #[Groups(['list:read'])]
    public ?string $titrePrincipal = null;
    #[Groups(['list:read'])]
    public ?string $contextePiste = null;
    #[Groups(['list:read'])]
    public ?float $taxeCourtierPayee = null;
    #[Groups(['list:read'])]
    public ?float $taxeCourtierSolde = null;
    #[Groups(['list:read'])]
    public ?float $taxeAssureurPayee = null;
    #[Groups(['list:read'])]
    public ?float $taxeAssureurSolde = null;
    #[Groups(['list:read'])]
    public ?float $retroCommission = null;
    #[Groups(['list:read'])]
    public ?float $retroCommissionReversee = null;
    #[Groups(['list:read'])]
    public ?float $retroCommissionSolde = null;
    // NOUVEAU : Attributs calculés (Miroir de Cotation)
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
    #[Groups(['list:read'])]
    public ?\DateTimeInterface $dateDernierReglement = null;
    #[Groups(['list:read'])]
    public ?string $vitesseReglement = null;
    #[Groups(['list:read'])]
    public ?int $nombreTranches = null;
    #[Groups(['list:read'])]
    public ?float $montantMoyenTranche = null;
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
    public ?float $montantPur = null;
    #[Groups(['list:read'])]
    public ?float $reserve = null;
    public const RENEWAL_STATUS_RENEWED     = 2;
    public const RENEWAL_STATUS_EXTENDED    = 3;
    public const RENEWAL_STATUS_RUNNING     = 4;
    public const RENEWAL_STATUS_RENEWING    = 5;
    public const RENEWAL_STATUS_CANCELLED   = 6;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $startingAt = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $endingAt = null;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'avenant', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    #[ORM\ManyToOne(inversedBy: 'avenants', cascade: ['persist', 'remove'])]
    #[Groups(['list:read'])]
    private ?Cotation $cotation = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $referencePolice = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $numero = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['list:read'])]
    private ?int $renewalStatus = self::RENEWAL_STATUS_RUNNING;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    // public function getType(): ?int
    // {
    //     return $this->type;
    // }

    // public function setType(int $type): static
    // {
    //     $this->type = $type;

    //     return $this;
    // }

    public function getStartingAt(): ?\DateTimeImmutable
    {
        return $this->startingAt;
    }

    public function setStartingAt(\DateTimeImmutable $startingAt): static
    {
        $this->startingAt = $startingAt;

        return $this;
    }

    public function getEndingAt(): ?\DateTimeImmutable
    {
        return $this->endingAt;
    }

    public function setEndingAt(\DateTimeImmutable $endingAt): static
    {
        $this->endingAt = $endingAt;

        return $this;
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
            $document->setAvenant($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getAvenant() === $this) {
                $document->setAvenant(null);
            }
        }

        return $this;
    }

    public function getCotation(): ?Cotation
    {
        return $this->cotation;
    }

    public function setCotation(?Cotation $cotation): static
    {
        $this->cotation = $cotation;

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

    public function getReferencePolice(): ?string
    {
        return $this->referencePolice;
    }

    public function setReferencePolice(string $referencePolice): static
    {
        $this->referencePolice = $referencePolice;

        return $this;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(?string $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

    public function __toString()
    {
        return $this->numero;
    }

    public function getRenewalStatus(): ?int
    {
        return $this->renewalStatus;
    }

    public function setRenewalStatus(?int $renewalStatus): static
    {
        $this->renewalStatus = $renewalStatus;

        return $this;
    }
}
