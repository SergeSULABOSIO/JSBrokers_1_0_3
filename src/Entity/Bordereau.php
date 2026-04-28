<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use Symfony\Component\Serializer\Annotation\Groups;
use App\Repository\BordereauRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BordereauRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Bordereau implements OwnerAwareInterface
{
    use AuditableTrait;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $type = null;

    // NOUVEAU : Statuts adaptés au flux "entrant"
    public const STATUT_A_VERIFIER = 0;       // Reçu, en attente de vérification par le courtier
    public const STATUT_CONTESTE = 1;         // Le courtier a signalé une anomalie
    public const STATUT_VALIDE = 2;           // Vérifié et conforme, en attente de paiement
    public const STATUT_PAYE = 3;             // L'assureur a payé la totalité
    public const STATUT_PARTIELLEMENT_PAYE = 4; // Paiement partiel reçu
    public const STATUT_ANNULE = 5;           // Le bordereau a été annulé par l'assureur
    public const TYPE_BOREDERAU_PRODUCTION = 0;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    #[ORM\ManyToOne(inversedBy: 'bordereaus')]
    #[Groups(['list:read'])]
    private ?Assureur $assureur = null;

    // Le nom 'receivedAt' est parfait ici. C'est la date de réception du document.
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $receivedAt = null;

    // NOUVEAU : La référence unique du bordereau, essentielle pour la communication et le suivi.
    #[ORM\Column(length: 255, unique: true)]
    #[Groups(['list:read'])]
    private ?string $reference = null;

    // NOUVEAU : Période couverte par le bordereau. Indispensable.
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $periodeDebut = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $periodeFin = null;

    // NOUVEAU : Date à laquelle le paiement a été reçu par le courtier.
    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?float $montantCommissionHT = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $montantTaxe = null;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'bordereau', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    /**
     * @var Collection<int, Note>
     */
    #[ORM\OneToMany(targetEntity: Note::class, mappedBy: 'bordereau')]
    private Collection $notes;

    // NOUVEAU : Le statut actuel du bordereau.
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $statut = self::STATUT_A_VERIFIER;
 
    // NOUVEAU : Attributs calculés pour l'affichage et l'analyse
    #[Groups(['list:read'])]
    public ?string $typeString = null;
 
    #[Groups(['list:read'])]
    public ?string $ageBordereau = null;
 
    #[Groups(['list:read'])]
    public ?string $delaiSoumission = null;
 
    #[Groups(['list:read'])]
    public ?int $nombreDocuments = null;

    // NOUVEAU : Attributs calculés financiers
    #[Groups(['list:read'])]
    public ?float $montantCommissionTTC = null;

    #[Groups(['list:read'])]
    public ?float $montantEncaisse = null;
    #[Groups(['list:read'])]
    public ?float $solde = null;
   

    public function __construct()
    {
        $this->documents = new ArrayCollection();
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

    public function getAssureur(): ?Assureur
    {
        return $this->assureur;
    }

    public function setAssureur(?Assureur $assureur): static
    {
        $this->assureur = $assureur;

        return $this;
    }

    public function getReceivedAt(): ?\DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(\DateTimeImmutable $receivedAt): static
    {
        $this->receivedAt = $receivedAt;

        return $this;
    }

    public function getMontantCommissionHT(): ?float
    {
        return $this->montantCommissionHT;
    }

    public function setMontantCommissionHT(float $montantCommissionHT): static
    {
        $this->montantCommissionHT = $montantCommissionHT;

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
            $document->setBordereau($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getBordereau() === $this) {
                $document->setBordereau(null);
            }
        }

        return $this;
    }

    public function getType(): ?int
    {
        return $this->type;
    }

    public function setType(int $type): static
    {
        $this->type = $type;

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
            $note->setBordereau($this);
        }

        return $this;
    }

    public function removeNote(Note $note): static
    {
        if ($this->notes->removeElement($note)) {
            // set the owning side to null (unless already changed)
            if ($note->getBordereau() === $this) {
                $note->setBordereau(null);
            }
        }

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getPeriodeDebut(): ?\DateTimeImmutable
    {
        return $this->periodeDebut;
    }

    public function setPeriodeDebut(\DateTimeImmutable $periodeDebut): static
    {
        $this->periodeDebut = $periodeDebut;

        return $this;
    }

    public function getPeriodeFin(): ?\DateTimeImmutable
    {
        return $this->periodeFin;
    }

    public function setPeriodeFin(\DateTimeImmutable $periodeFin): static
    {
        $this->periodeFin = $periodeFin;

        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getMontantTaxe(): ?float
    {
        return $this->montantTaxe;
    }

    public function setMontantTaxe(?float $montantTaxe): static
    {
        $this->montantTaxe = $montantTaxe;

        return $this;
    }

    public function getStatut(): ?int
    {
        return $this->statut;
    }

    public function setStatut(int $statut): static
    {
        $this->statut = $statut;

        return $this;
    }
}
