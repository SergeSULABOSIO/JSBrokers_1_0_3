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
    // QU'EST DEVENUE CETTE POLICE ? Statut de la SUITE (« Renouvelée »,
    // « Renouvellement en cours », « Non renouvelée »…) et fait rédigé qui NOMME
    // l'avenant successeur — ou affirme son absence comme une VÉRIFICATION.
    // Source unique : AvenantRenouvellementResolver. Sans ces deux attributs,
    // l'assistante ne voyait que « une piste dérivée existe » (hasPisteDerivee) et
    // en déduisait à tort « pas encore renouvelée » (bug KIN AVIA).
    #[Groups(['list:read'])]
    public ?string $statutRenouvellement = null;
    #[Groups(['list:read'])]
    public ?string $suiteDeLaPolice = null;
    // Présence d'une piste dérivée (renouvellement/prorogation/ajustement lié à cet
    // avenant de base) : sert de condition aux attribute_actions « piste dérivée ».
    // NE DIT RIEN de ce que cette piste a produit : voir $suiteDeLaPolice.
    #[Groups(['list:read'])]
    public ?bool $hasPisteDerivee = null;
    // Libellé de la ligne secondaire de la liste : « Piste dérivée » UNIQUEMENT si
    // l'avenant en a une ; null sinon (l'item n'est alors pas rendu — économie d'espace).
    #[Groups(['list:read'])]
    public ?string $pisteDeriveeLibelle = null;
    // Décision de non-renouvellement rendue LISIBLE, partout où la police s'affiche : badge de
    // liste (+ son niveau) et fait rédigé nommant la date, l'auteur et le motif. Tous null
    // quand la police n'est pas marquée — l'item n'est alors rendu nulle part. Source unique :
    // AvenantIndicatorStrategy ; aucun libellé n'est réécrit dans un gabarit.
    #[Groups(['list:read'])]
    public ?string $nonRenouvelableBadge = null;
    #[Groups(['list:read'])]
    public ?string $nonRenouvelableNiveau = null;
    #[Groups(['list:read'])]
    public ?string $nonRenouvelableDetail = null;
    // Rappel de la décision RÉVISÉE (marquage posé puis levé). Visible uniquement dans les
    // attributs calculés et les fiches — jamais en badge ni en liste : la police est redevenue
    // ordinaire, seul son historique reste consultable.
    #[Groups(['list:read'])]
    public ?string $nonRenouvelableHistorique = null;
    #[Groups(['list:read'])]
    public ?string $dureeCouverture = null;
    #[Groups(['list:read'])]
    public ?string $joursRestants = null;
    // Urgence d'échéance : libellé affiché en badge sur la liste (« Expiré depuis N j »,
    // « Échéance dans N j »…) + niveau technique (classe CSS du badge). Dérivé de endingAt
    // par AvenantEcheanceScope — miroir de Tranche::$urgenceRecouvrement / $urgenceNiveau.
    #[Groups(['list:read'])]
    public ?string $urgenceEcheance = null;
    #[Groups(['list:read'])]
    public ?string $urgenceEcheanceNiveau = null;
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

    #[ORM\ManyToOne(targetEntity: Piste::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['list:read'])]
    private ?Piste $pisteDeRenouvellement = null;

    // DÉCISION DE NE PAS RENOUVELER — signalée par le courtier À TOUT MOMENT de la vie de la
    // police (dès qu'il apprend l'information, pas seulement à l'échéance). Elle fait sortir la
    // police du PIPELINE D'ÉCHÉANCE via AvenantSuccessionScope, et de RIEN D'AUTRE : la
    // couverture court jusqu'à son terme, renewalStatus n'est pas touché, et tout ce qui reste
    // dû (prime, commission, taxes, rétrocommissions) continue d'être réclamé par les suivis
    // de recouvrement. Ce n'est ni une résiliation ni une annulation.
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['list:read'])]
    private bool $nonRenouvelable = false;

    // Le POURQUOI, écrit pour celui qui rouvrira le dossier des mois plus tard. Note INTERNE :
    // ne doit jamais sortir vers le client (cf. templates/admin/soa/*).
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['list:read'])]
    private ?string $nonRenouvelableMotif = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $nonRenouvelableLe = null;

    // Pas de Groups : sérialiser la relation embarquerait tout le graphe Invite dans chaque
    // ligne de liste. Le nom de l'auteur est publié par l'indicateur calculé nonRenouvelableDetail.
    #[ORM\ManyToOne(targetEntity: Invite::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Invite $nonRenouvelablePar = null;

    // Date de LEVÉE du marquage. Non nulle = la police a été marquée puis rendue à nouveau
    // renouvelable ; les trois champs ci-dessus CONSERVENT alors la décision révisée — les
    // effacer supprimerait précisément ce que ce dispositif existe pour garder.
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $nonRenouvelableLeveLe = null;

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

    // ------------------------------------------------- décision de non-renouvellement

    public function isNonRenouvelable(): bool
    {
        return $this->nonRenouvelable;
    }

    /**
     * SEUL LE BOOLÉEN GOUVERNE. Un motif non vide ne signifie pas « marquée » : après une
     * levée, la trace subsiste alors que la police est redevenue renouvelable. Badges,
     * bandeau, prédicat SQL, resolver et actions conditionnelles testent tous ce booléen,
     * jamais la présence du texte.
     *
     * L'horodatage vit ici, et non dans les appelants, pour que le chemin écran et le chemin
     * de l'assistante (qui écrit via AvenantType) se comportent identiquement.
     */
    public function setNonRenouvelable(?bool $nonRenouvelable): static
    {
        $nouveau = (bool) $nonRenouvelable;

        if ($nouveau !== $this->nonRenouvelable) {
            if ($nouveau) {
                $this->nonRenouvelableLe     = new \DateTimeImmutable('now');
                $this->nonRenouvelableLeveLe = null;
            } else {
                // Levée : on DATE la révision, on n'efface rien.
                $this->nonRenouvelableLeveLe = new \DateTimeImmutable('now');
            }
        }

        $this->nonRenouvelable = $nouveau;

        return $this;
    }

    public function getNonRenouvelableMotif(): ?string
    {
        return $this->nonRenouvelableMotif;
    }

    public function setNonRenouvelableMotif(?string $motif): static
    {
        $this->nonRenouvelableMotif = $motif;

        return $this;
    }

    public function getNonRenouvelableLe(): ?\DateTimeImmutable
    {
        return $this->nonRenouvelableLe;
    }

    public function setNonRenouvelableLe(?\DateTimeImmutable $le): static
    {
        $this->nonRenouvelableLe = $le;

        return $this;
    }

    public function getNonRenouvelablePar(): ?Invite
    {
        return $this->nonRenouvelablePar;
    }

    public function setNonRenouvelablePar(?Invite $par): static
    {
        $this->nonRenouvelablePar = $par;

        return $this;
    }

    public function getNonRenouvelableLeveLe(): ?\DateTimeImmutable
    {
        return $this->nonRenouvelableLeveLe;
    }

    public function setNonRenouvelableLeveLe(?\DateTimeImmutable $le): static
    {
        $this->nonRenouvelableLeveLe = $le;

        return $this;
    }

    public function getMontantHT(): ?float
    {
        return $this->montantHT;
    }

    public function getMontantTTC(): ?float
    {
        return $this->montantTTC;
    }

    public function getTaxeAssureurMontant(): ?float
    {
        return $this->taxeAssureurMontant;
    }

    public function getTauxCommission(): ?float
    {
        return $this->tauxCommission;
    }

    public function getPisteDeRenouvellement(): ?Piste
    {
        return $this->pisteDeRenouvellement;
    }

    public function setPisteDeRenouvellement(?Piste $pisteDeRenouvellement): static
    {
        $this->pisteDeRenouvellement = $pisteDeRenouvellement;
        return $this;
    }
}
