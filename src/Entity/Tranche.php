<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\TrancheRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: TrancheRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Tranche
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

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $montantFlat = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $pourcentage = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $payableAt = null;

    #[ORM\ManyToOne(inversedBy: 'tranches')]
    private ?Cotation $cotation = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $echeanceAt = null;

    /*
     * ÉCHÉANCE « À RÉGULARISER » — après une annulation ou une résiliation de sa police.
     *
     * Une échéance qui devient exigible le jour de la date d'effet de l'acte, ou après,
     * couvre une période où la police n'existe plus : la réclamer, ce serait relancer le
     * client pour une prime qu'il ne doit pas. Elle SORT DU RECOUVREMENT (relances, alertes,
     * primes à encaisser — écran et Ket) sans que ses montants changent : la ristourne
     * éventuelle se fait à la main, par note de crédit. Règle : RegularisationDesEcheances.
     *
     * Trois colonnes, sur le principe du statut de la police de base
     * (Piste::$statutBaseAvantMouvement) : l'état, le mouvement qui l'a posé, et l'état
     * d'AVANT — restitué tel quel si le mouvement est abandonné (LiensProteges::dissocier).
     */
    public const REGULARISATION_A_REGULARISER = 'a_regulariser';
    /** Déclarée due malgré l'acte (la prime reste acquise à l'assureur) : recouvrement normal. */
    public const REGULARISATION_MAINTENUE = 'maintenue';
    /**
     * Clôturée SANS AVOIR : rien n'avait été facturé sur l'échéance, il n'y a donc rien à
     * créditer — la régularisation est faite. Réversible, et restituée à l'abandon.
     */
    public const REGULARISATION_CLOTUREE = 'cloturee';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $regularisation = null;

    #[ORM\ManyToOne(targetEntity: Piste::class, inversedBy: 'tranchesRegularisees')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Piste $regularisationPar = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $regularisationAvant = null;

    /**
     * @var Collection<int, Article>
     */
    #[ORM\OneToMany(targetEntity: Article::class, mappedBy: 'tranche')]
    private Collection $articles;

    /**
     * @var Collection<int, PaiementPrime> Signalements de paiement de la prime par
     *      l'assuré (encaissée par l'ASSUREUR — jamais la trésorerie du courtier) :
     *      trace déclarative qui rend la commission de courtage exigible.
     */
    #[ORM\OneToMany(targetEntity: PaiementPrime::class, mappedBy: 'tranche', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $paiementsPrime;

    #[Groups(['list:read'])]
    public ?string $contexteParent = null;

    #[Groups(['list:read'])]
    public ?string $ageTranche = null;

    #[Groups(['list:read'])]
    public ?string $joursRestantsAvantEcheance = null;

    #[Groups(['list:read'])]
    public ?float $pourcentageAffiche = null;

    #[Groups(['list:read'])]
    public ?string $clientNom = null;

    #[Groups(['list:read'])]
    public ?string $cotationNom = null;

    #[Groups(['list:read'])]
    public ?string $nomCompletAvecStatut = null;

    // NOUVEAU : Attributs liés à la police
    #[Groups(['list:read'])]
    public ?string $referencePolice = null;

    #[Groups(['list:read'])]
    public ?string $periodeCouverture = null;

    #[Groups(['list:read'])]
    public ?string $assureurNom = null;

    // NOUVEAU : Attributs calculés spécifiques (Miroir de RevenuPourCourtier + Taux Tranche)
    #[Groups(['list:read'])]
    public ?float $tauxTranche = null;

    #[Groups(['list:read'])]
    public ?float $primeTranche = null;

    #[Groups(['list:read'])]
    public ?float $primePayee = null;

    #[Groups(['list:read'])]
    public ?float $primeSoldeDue = null;

    #[Groups(['list:read'])]
    public ?float $montantCalculeHT = null;

    #[Groups(['list:read'])]
    public ?float $montantCalculeTTC = null;

    #[Groups(['list:read'])]
    public ?string $descriptionCalcul = null;

    #[Groups(['list:read'])]
    public ?float $montant_du = null;

    #[Groups(['list:read'])]
    public ?float $montant_paye = null;

    #[Groups(['list:read'])]
    public ?float $solde_restant_du = null;

    #[Groups(['list:read'])]
    public ?float $montantPur = null;

    #[Groups(['list:read'])]
    public ?float $partPartenaire = null;

    #[Groups(['list:read'])]
    public ?float $retroCommission = null;

    #[Groups(['list:read'])]
    public ?float $reserve = null;

    #[Groups(['list:read'])]
    public ?float $retroCommissionReversee = null;

    #[Groups(['list:read'])]
    public ?float $retroCommissionSolde = null;

    #[Groups(['list:read'])]
    public ?float $taxeCourtierMontant = null;

    #[Groups(['list:read'])]
    public ?float $taxeCourtierTaux = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurMontant = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurTaux = null;

    #[Groups(['list:read'])]
    public ?string $estPartageable = null;

    #[Groups(['list:read'])]
    public ?float $taxeCourtierPayee = null;

    #[Groups(['list:read'])]
    public ?float $taxeCourtierSolde = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurPayee = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurSolde = null;

    // NOUVEAU : 5 Attributs calculés supplémentaires pour le suivi
    #[Groups(['list:read'])]
    public ?string $statutPaiement = null;

    #[Groups(['list:read'])]
    public ?float $tauxAvancement = null;

    #[Groups(['list:read'])]
    public ?float $resteAPayer = null;

    #[Groups(['list:read'])]
    public ?string $retardPaiement = null;

    #[Groups(['list:read'])]
    public ?\DateTimeInterface $dateDernierEncaissement = null;

    // QUAND LA PRIME A ÉTÉ RÉGLÉE, et par quelle pièce on le sait. Déclarées ici — et pas
    // posées dynamiquement — pour la raison donnée plus bas à propos de `retroAgentDue` :
    // hors groupe de sérialisation, une valeur calculée n'atteint ni le `data-entity`
    // d'une ligne ni les actions qui s'y conditionnent.
    #[Groups(['list:read'])]
    public ?\DateTimeInterface $primePayeeLe = null;

    #[Groups(['list:read'])]
    public ?string $primePayeeOrigine = null;

    // Urgence de recouvrement (prime et/ou commission à collecter) : libellé affiché
    // en badge sur la liste + niveau technique (classe CSS / restitution assistant IA).
    #[Groups(['list:read'])]
    public ?string $urgenceRecouvrement = null;

    #[Groups(['list:read'])]
    public ?string $urgenceNiveau = null;

    // Rétrocommission partenaire exigible (solde dû, commission partageable encaissée) :
    // montant + libellé du badge « Rétro partenaire à payer » de la liste.
    #[Groups(['list:read'])]
    public ?float $retroCommissionExigible = null;

    // Rétrocommission des AGENTS INTERNES, à la maille de cette échéance : le dû proratisé,
    // et la part réclamable une fois la commission de CETTE échéance encaissée.
    //
    // DÉCLARÉES, et pas seulement produites : `retroAgentDue` était posée en propriété
    // dynamique, ce que PHP 8.2 déprécie — la suite le signalait à chaque exécution — et ce
    // qui la laissait hors de tout groupe de sérialisation, donc invisible du `data-entity`
    // d'une ligne et de toute action conditionnée dessus.
    #[Groups(['list:read'])]
    public ?float $retroAgentDue = null;

    #[Groups(['list:read'])]
    public ?float $retroAgentExigible = null;

    // ⚠ MÊME OMISSION QUE `retroAgentDue`, restée en place : TrancheIndicatorStrategy
    // posait ces deux-là en propriétés dynamiques. PHP 8.2 le déprécie — la suite de
    // tests le signalait trente-neuf fois par exécution — et cela les laissait hors de
    // tout groupe de sérialisation, donc invisibles du `data-entity` d'une ligne. Le
    // versé et le solde d'un agent ne sont pas moins légitimes que son dû.
    #[Groups(['list:read'])]
    public ?float $retroAgentReversee = null;

    #[Groups(['list:read'])]
    public ?float $retroAgentSolde = null;

    #[Groups(['list:read'])]
    public ?string $retroAPayerAffiche = null;

    // Commission de courtage exigible auprès de l'assureur (prime payée par l'assuré —
    // facturée OU signalée via PaiementPrime — et commission non collectée).
    #[Groups(['list:read'])]
    public ?float $commissionExigible = null;

    #[Groups(['list:read'])]
    public ?string $commissionExigibleAffiche = null;

    // Cumul des paiements de prime signalés (déclaratif, hors trésorerie courtier).
    #[Groups(['list:read'])]
    public ?float $primeDeclareePayee = null;

    // Indicateurs d'affichage de la liste (taxes/commission/rétro-commission formatées) :
    // déclarés pour éviter les propriétés dynamiques (dépréciées en PHP 8.2).
    #[Groups(['list:read'])]
    public ?string $clientDescription = null;

    #[Groups(['list:read'])]
    public ?string $risqueDescription = null;

    #[Groups(['list:read'])]
    public ?string $taxeCourtierAffichee = null;

    #[Groups(['list:read'])]
    public ?string $taxeAssureurAffichee = null;

    #[Groups(['list:read'])]
    public ?string $commissionTTCAffichee = null;

    #[Groups(['list:read'])]
    public ?string $retroCommissionAffichee = null;

    // LE VOYANT DU PARTAGE, ET LES DEUX DRAPEAUX DE SES ACTIONS.
    //
    // Une valeur calculée posée en propriété DYNAMIQUE n'appartient à aucun groupe de
    // sérialisation : elle ne figure pas dans le `data-entity` de la ligne, et une action
    // conditionnée dessus reste INVISIBLE en barre d'outils comme au clic droit — sans la
    // moindre erreur, la condition valant `undefined`. D'où ces déclarations.
    //
    // ── UN SEUL CHAMP, ET C'EST UN VOYANT ───────────────────────────────────────────
    // Il a porté un temps deux drapeaux à ses côtés — « rattachable » et « détachable » —
    // qui gouvernaient deux actions de barre d'outils. Ils n'ont pas survécu : depuis que
    // chaque ligne du picker porte SON verbe (rattacher ce qui est libre, détacher ce qui
    // est posé), il n'y a plus qu'une action, toujours offerte. Un état à gouverner de
    // moins est un état de moins à faire diverger.
    //
    // `partageLibelle` nomme ce qui existe (« Apporteur : SUNU · Effort : Alice »), et
    // `null` reste le cas NORMAL : l'affaire que le cabinet a gagnée seul.
    #[Groups(['list:read'])]
    public ?string $partageLibelle = null;

    // ── Régularisation après annulation / résiliation (TrancheIndicatorStrategy) ──
    /** a_regulariser | regularisee | maintenue | null — sert aussi de condition aux actions. */
    #[Groups(['list:read'])]
    public ?string $regularisationEtat = null;

    #[Groups(['list:read'])]
    public ?string $regularisationExplication = null;

    /** avoir | cloture | avoir_emis | cloturee | null — la voie de régularisation ouverte (conditions d'actions). */
    #[Groups(['list:read'])]
    public ?string $regularisationVoie = null;

    /** « Commission déjà facturée : note n° X, à régulariser auprès de l'assureur. » */
    #[Groups(['list:read'])]
    public ?string $commissionDejaFacturee = null;

    #[Groups(['list:read'])]
    public ?float $primeARegulariser = null;

    #[Groups(['list:read'])]
    public ?float $commissionARegulariser = null;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->reversementsRetroAgent = new ArrayCollection();
        $this->articles = new ArrayCollection();
        $this->paiementsPrime = new ArrayCollection();
    }

    /**
     * @var Collection<int, Document> Pièces jointes de cette fiche.
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'tranche', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    /**
     * @var Collection<int, ReversementRetroAgent> Rétrocommissions déjà versées au titre de
     *      CETTE échéance — c'est à ce rythme que la prime, la commission et donc la
     *      rémunération de l'intermédiaire circulent.
     *
     * Sans cascade remove, comme du côté de l'avenant : supprimer une échéance ne doit pas
     * effacer la trace d'un décaissement réel, qui est en comptabilité.
     */
    #[ORM\OneToMany(targetEntity: ReversementRetroAgent::class, mappedBy: 'tranche')]
    private Collection $reversementsRetroAgent;

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
            $document->setTranche($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getTranche() === $this) {
                $document->setTranche(null);
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

    public function getMontantFlat(): ?float
    {
        return $this->montantFlat;
    }

    public function setMontantFlat(?float $montantFlat): static
    {
        $this->montantFlat = $montantFlat;

        return $this;
    }

    public function getPourcentage(): ?float
    {
        return $this->pourcentage;
    }

    public function setPourcentage(?float $pourcentage): static
    {
        $this->pourcentage = $pourcentage;

        return $this;
    }

    /**
     * Fraction (0..1) dérivée du pourcentage stocké : SOURCE UNIQUE pour tout
     * calcul monétaire (prime × fraction, commission × fraction…). Le champ
     * `pourcentage` est stocké en POINTS (100 = 100 %, 33,33 = 33,33 %) — comme
     * l'affiche l'écran et l'import bordereau ; les calculs, eux, ont besoin de la
     * fraction. Ne jamais multiplier un montant par getPourcentage() directement.
     */
    public function getFraction(): float
    {
        return ($this->pourcentage ?? 0.0) / 100.0;
    }

    public function getPayableAt(): ?\DateTimeImmutable
    {
        return $this->payableAt;
    }

    public function setPayableAt(\DateTimeImmutable $payableAt): static
    {
        $this->payableAt = $payableAt;

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

    public function getEcheanceAt(): ?\DateTimeImmutable
    {
        return $this->echeanceAt;
    }

    public function getRegularisation(): ?string
    {
        return $this->regularisation;
    }

    public function getRegularisationPar(): ?Piste
    {
        return $this->regularisationPar;
    }

    public function getRegularisationAvant(): ?string
    {
        return $this->regularisationAvant;
    }

    /** L'échéance est-elle hors du recouvrement, en attente de régularisation ? */
    public function estARegulariser(): bool
    {
        return $this->regularisation === self::REGULARISATION_A_REGULARISER;
    }

    /**
     * Hors du recouvrement : en attente de régularisation, ou clôturée sans avoir. Rien n'y
     * est plus réclamé. (« Maintenue » y revient : elle est due.)
     */
    public function estHorsRecouvrement(): bool
    {
        return in_array($this->regularisation, [self::REGULARISATION_A_REGULARISER, self::REGULARISATION_CLOTUREE], true);
    }

    /**
     * Pose l'état « à régulariser » au nom d'un mouvement (annulation, résiliation), en
     * MÉMORISANT l'état d'avant. Appelé par le plan du mouvement : la relation se donne par
     * son identifiant (« @mouvement »), le moteur de mutation la pose par ce setter.
     * Sans effet si c'est déjà ce mouvement : un plan rejoué ne doit pas écraser l'état
     * d'avant par celui qu'il a lui-même posé.
     */
    public function setRegularisationPar(?Piste $mouvement): static
    {
        if ($mouvement === null) {
            return $this->leverRegularisation();
        }
        if ($mouvement !== $this->regularisationPar) {
            $this->regularisationAvant = $this->regularisation;
            $this->regularisation = self::REGULARISATION_A_REGULARISER;
            $this->regularisationPar = $mouvement;
        }

        return $this;
    }

    /** Abandon du mouvement : l'échéance reprend EXACTEMENT son état d'avant. */
    public function leverRegularisation(): static
    {
        if ($this->regularisationPar !== null) {
            $this->regularisation = $this->regularisationAvant;
        }
        $this->regularisationAvant = null;
        $this->regularisationPar = null;

        return $this;
    }

    /** « Déclarer due quand même » : la prime reste acquise, le recouvrement reprend. */
    public function declarerDue(): static
    {
        if ($this->regularisationPar !== null) {
            $this->regularisation = self::REGULARISATION_MAINTENUE;
        }

        return $this;
    }

    /** Rien n'a été facturé : la régularisation se clôt sans note de crédit. */
    public function cloturerSansAvoir(): static
    {
        if ($this->regularisationPar !== null) {
            $this->regularisation = self::REGULARISATION_CLOTUREE;
        }

        return $this;
    }

    /** Revenir sur « déclarée due » ou sur « clôturée sans avoir » : de nouveau à régulariser. */
    public function remettreARegulariser(): static
    {
        if ($this->regularisationPar !== null) {
            $this->regularisation = self::REGULARISATION_A_REGULARISER;
        }

        return $this;
    }

    public function setEcheanceAt(?\DateTimeImmutable $echeanceAt): static
    {
        $this->echeanceAt = $echeanceAt;

        return $this;
    }

    /**
     * @return Collection<int, Article>
     */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    /**
     * @return Collection<int, PaiementPrime>
     */
    public function getPaiementsPrime(): Collection
    {
        return $this->paiementsPrime;
    }

    public function addPaiementsPrime(PaiementPrime $paiementPrime): static
    {
        if (!$this->paiementsPrime->contains($paiementPrime)) {
            $this->paiementsPrime->add($paiementPrime);
            $paiementPrime->setTranche($this);
        }

        return $this;
    }

    public function removePaiementsPrime(PaiementPrime $paiementPrime): static
    {
        if ($this->paiementsPrime->removeElement($paiementPrime)) {
            if ($paiementPrime->getTranche() === $this) {
                $paiementPrime->setTranche(null);
            }
        }

        return $this;
    }

    public function addArticle(Article $article): static
    {
        if (!$this->articles->contains($article)) {
            $this->articles->add($article);
            $article->setTranche($this);
        }

        return $this;
    }

    public function removeArticle(Article $article): static
    {
        if ($this->articles->removeElement($article)) {
            // set the owning side to null (unless already changed)
            if ($article->getTranche() === $this) {
                $article->setTranche(null);
            }
        }

        return $this;
    }

    public function __toString()
    {
        return ($this->cotation != null ? $this->cotation->getNom() : "") . " / " . $this->id . " / " . $this->nom;
    }

    /**
     * @return Collection<int, ReversementRetroAgent>
     */
    public function getReversementsRetroAgent(): Collection
    {
        return $this->reversementsRetroAgent;
    }
}
