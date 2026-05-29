<?php

namespace App\Entity;

use Symfony\Component\Serializer\Annotation\Groups;
use App\Repository\BordereauRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BordereauRepository::class)]
#[ORM\HasLifecycleCallbacks] // Garder HasLifecycleCallbacks pour updatedAt
class Bordereau implements OwnerAwareInterface // Implémente OwnerAwareInterface pour setInvite
{    
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // #[Groups(['list:read'])] // Removed as it was causing issues with serialization in some contexts
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
    
    // NOUVEAU : Statuts spécifiques au processus d'analyse du bordereau
    public const STATUT_EN_ATTENTE_ANALYSE = 10;
    public const STATUT_SELECTION_FEUILLE_EN_COURS = 11;
    public const STATUT_MAPPAGE_EN_COURS = 12;
    public const STATUT_MAPPAGE_COMPLET = 13;
    public const STATUT_MAPPAGE_INCOMPLET = 14;
    public const STATUT_ANALYSE_TERMINEE = 15;
    public const STATUT_FACTURE = 3;              // Note de facturation émise
    public const STATUT_INCONNU = 99;
    public const TYPE_BOREDERAU_PRODUCTION = 0;
    public const STEP_NOTE_EMISE = 20;            // currentAnalysisStep persisté → "Facturé"
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    #[ORM\ManyToOne(inversedBy: 'bordereaus')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Invite $invite = null;

    #[ORM\ManyToOne(inversedBy: 'bordereaus')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Entreprise $entreprise = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

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
    
    #[Groups(['list:read'])]
    public ?float $montantCommissionHT = null; // Attribut calculé

    #[Groups(['list:read'])]
    public ?float $montantTaxe = null; // Attribut calculé

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

    /**
     * @var Collection<int, Operation>
     */
    #[ORM\OneToMany(targetEntity: Operation::class, mappedBy: 'bordereau', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $operations;

    // NOUVEAU : Le statut actuel du bordereau.
    #[Groups(['list:read'])]
    // L'attribut 'statut' est maintenant calculé et non stocké en base de données.
    public ?int $statut = null;
 
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
   
    // NOUVEAU : Champs pour stocker l'état de l'analyse du bordereau
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $selectedSheetName = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['list:read'])]
    private ?array $mappedColumns = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?int $currentAnalysisStep = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['list:read'])]
    private ?array $analysisResults = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $montantPayableNow = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $montantComHtPayableNow = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $montantTaxePayableNow = null;

    // Propriété publique non-persistée, hydratée par CanvasBuilder pour l'affichage sur la liste
    #[Groups(['list:read'])]
    public ?float $montantPayableDisplay = null;

    #[Groups(['list:read'])]
    public ?float $comHtPayableNow = null;

    #[Groups(['list:read'])]
    public ?float $taxePayableNow = null;

    #[Groups(['list:read'])]
    public ?float $comTtcPayableNow = null;


    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->notes = new ArrayCollection();
        $this->operations = new ArrayCollection();
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

    public function setPeriodeFin(?\DateTimeImmutable $periodeFin): static
    {
        $this->periodeFin = $periodeFin;

        return $this;
    }

    public function getStatut(): ?int
    {
        return $this->statut;
    }

    public function setStatut(?int $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    /**
     * @return Collection<int, Operation>
     */
    public function getOperations(): Collection
    {
        return $this->operations;
    }

    public function addOperation(Operation $operation): static
    {
        if (!$this->operations->contains($operation)) {
            $this->operations->add($operation);
            $operation->setBordereau($this);
        }

        return $this;
    }

    public function removeOperation(Operation $operation): static
    {
        if ($this->operations->removeElement($operation)) {
            // set the owning side to null (unless already changed)
            if ($operation->getBordereau() === $this) {
                $operation->setBordereau(null);
            }
        }

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

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): static
    {
        $this->entreprise = $entreprise;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getSelectedSheetName(): ?string
    {
        return $this->selectedSheetName;
    }

    public function setSelectedSheetName(?string $selectedSheetName): static
    {
        $this->selectedSheetName = $selectedSheetName;

        return $this;
    }

    public function getMappedColumns(): ?array
    {
        return $this->mappedColumns;
    }

    public function setMappedColumns(?array $mappedColumns): static
    {
        $this->mappedColumns = $mappedColumns;

        return $this;
    }

    public function getCurrentAnalysisStep(): ?int
    {
        return $this->currentAnalysisStep;
    }

    public function setCurrentAnalysisStep(?int $currentAnalysisStep): static
    {
        $this->currentAnalysisStep = $currentAnalysisStep;

        return $this;
    }

    public function getAnalysisResults(): ?array
    {
        return $this->analysisResults;
    }

    public function setAnalysisResults(?array $analysisResults): static
    {
        $this->analysisResults = $analysisResults;

        return $this;
    }

    public function getMontantPayableNow(): ?float
    {
        return $this->montantPayableNow;
    }

    public function setMontantPayableNow(?float $montantPayableNow): static
    {
        $this->montantPayableNow = $montantPayableNow;

        return $this;
    }

    public function getMontantComHtPayableNow(): ?float
    {
        return $this->montantComHtPayableNow;
    }

    public function setMontantComHtPayableNow(?float $value): static
    {
        $this->montantComHtPayableNow = $value;

        return $this;
    }

    public function getMontantTaxePayableNow(): ?float
    {
        return $this->montantTaxePayableNow;
    }

    public function setMontantTaxePayableNow(?float $value): static
    {
        $this->montantTaxePayableNow = $value;

        return $this;
    }
}
