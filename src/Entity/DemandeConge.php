<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\DemandeCongeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @file Demande de congé d'un collaborateur (module Administration → Congés).
 * @description L'agent est un Invite — un membre de l'équipe du cabinet —, jamais un
 * Utilisateur ni un salarié JS Brokers.
 *
 * IL N'Y A PAS D'ÉTAT « CONSOMMÉE ». Une demande approuvée dont la date de fin est
 * passée s'affiche simplement comme échue : cela évite une tâche de bascule nocturne et
 * un état de plus à maintenir. De même, le mouvement de compteur est écrit à
 * l'APPROBATION et non au retour de la date — aucune tâche planifiée n'est nécessaire.
 *
 * Le décompte est FIGÉ à la soumission par CalculateurJoursOuvrables : une correction
 * ultérieure du calendrier des fériés ou du régime de travail ne réécrit pas
 * l'historique.
 */
#[ORM\Entity(repositoryClass: DemandeCongeRepository::class)]
#[ORM\HasLifecycleCallbacks]
class DemandeConge implements OwnerAwareInterface
{
    use AuditableTrait;

    public const STATUT_BROUILLON = 'BROUILLON';
    public const STATUT_SOUMISE = 'SOUMISE';
    public const STATUT_APPROUVEE = 'APPROUVEE';
    public const STATUT_REFUSEE = 'REFUSEE';
    public const STATUT_ANNULEE = 'ANNULEE';

    /** Canal d'origine de l'écriture. L'auteur enregistré reste l'humain (RG-22). */
    public const ORIGINE_UI = 'UI';
    public const ORIGINE_KET = 'KET';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    /** Le collaborateur qui pose le congé. */
    #[ORM\ManyToOne(inversedBy: 'demandesConge')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Invite $agent = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?TypeAbsence $typeAbsence = null;

    #[Assert\NotNull(message: 'La date de début est obligatoire.')]
    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $dateDebut = null;

    #[Assert\NotNull(message: 'La date de fin est obligatoire.')]
    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $dateFin = null;

    /** Le premier jour n'est travaillé que le matin : on retire une demi-journée. */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private bool $demiJourneeDebut = false;

    /** Le dernier jour n'est travaillé que le matin : on retire une demi-journée. */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private bool $demiJourneeFin = false;

    /** Nombre de jours ouvrables décomptés, figé à la soumission. */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 1, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $nbJours = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $motif = null;

    #[ORM\Column(length: 20)]
    #[Groups(['list:read'])]
    private string $statut = self::STATUT_BROUILLON;

    /** Qui a rendu la décision. Null tant qu'aucune décision n'est prise. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Invite $valideur = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $dateDecision = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $commentaireDecision = null;

    #[ORM\Column(length: 10)]
    #[Groups(['list:read'])]
    private string $origine = self::ORIGINE_UI;

    /**
     * Justificatifs (certificat médical, acte…) et toute pièce du dossier.
     *
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'demandeConge', cascade: ['persist'])]
    private Collection $documents;

    /**
     * @var Collection<int, HistoriqueDemande>
     */
    #[ORM\OneToMany(targetEntity: HistoriqueDemande::class, mappedBy: 'demande', cascade: ['persist'])]
    private Collection $historiques;

    // ── INDICATEURS CALCULÉS ─────────────────────────────────────────────────────────
    // Un canevas de liste ne sait lire qu'un attribut PLAT, sans chemin pointé : écrire
    // « agent.nom » casserait le rendu dès la première ligne. Ces propriétés sont
    // peuplées par DemandeCongeIndicatorStrategy et par elle seule.

    #[Groups(['list:read'])]
    public ?string $agentNom = null;

    #[Groups(['list:read'])]
    public ?string $typeAbsenceLibelle = null;

    #[Groups(['list:read'])]
    public ?string $statutLibelle = null;

    #[Groups(['list:read'])]
    public ?string $periodeLibelle = null;

    #[Groups(['list:read'])]
    public ?string $valideurNom = null;

    /** Solde disponible de l'agent sur l'exercice de la demande (contexte du valideur). */
    #[Groups(['list:read'])]
    public ?float $soldeDisponibleAgent = null;

    #[Groups(['list:read'])]
    public ?int $nombreDocuments = null;

    // ── DRAPEAUX D'ACTIONS ───────────────────────────────────────────────────────────
    // Ils gouvernent la visibilité des entrées « Soumettre / Approuver / Refuser /
    // Annuler » de la barre d'outils et du clic droit.
    //
    // ⚠ ILS DOIVENT ÊTRE DÉCLARÉS ICI, dans le groupe list:read. Un drapeau seulement
    // posé dynamiquement par la stratégie d'indicateurs ne voyage pas jusqu'à la LIGNE
    // de liste : l'action reste alors invisible en barre d'outils et en menu contextuel,
    // sans la moindre erreur pour le signaler.
    //
    // Ce ne sont qu'un CONFORT D'INTERFACE : la vraie règle est rejouée côté serveur par
    // DemandeCongeWorkflow à chaque exécution.

    #[Groups(['list:read'])]
    public ?bool $peutEtreSoumise = null;

    #[Groups(['list:read'])]
    public ?bool $peutEtreDecidee = null;

    #[Groups(['list:read'])]
    public ?bool $peutEtreAnnulee = null;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->historiques = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAgent(): ?Invite
    {
        return $this->agent;
    }

    public function setAgent(?Invite $agent): static
    {
        $this->agent = $agent;

        return $this;
    }

    public function getTypeAbsence(): ?TypeAbsence
    {
        return $this->typeAbsence;
    }

    public function setTypeAbsence(?TypeAbsence $typeAbsence): static
    {
        $this->typeAbsence = $typeAbsence;

        return $this;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function isDemiJourneeDebut(): bool
    {
        return $this->demiJourneeDebut;
    }

    public function setDemiJourneeDebut(bool $demiJourneeDebut): static
    {
        $this->demiJourneeDebut = $demiJourneeDebut;

        return $this;
    }

    public function isDemiJourneeFin(): bool
    {
        return $this->demiJourneeFin;
    }

    public function setDemiJourneeFin(bool $demiJourneeFin): static
    {
        $this->demiJourneeFin = $demiJourneeFin;

        return $this;
    }

    public function getNbJours(): ?string
    {
        return $this->nbJours;
    }

    public function setNbJours(?string $nbJours): static
    {
        $this->nbJours = $nbJours;

        return $this;
    }

    /** Le décompte en nombre, pour les calculs — la colonne est un décimal Doctrine. */
    public function nbJoursFloat(): float
    {
        return (float) ($this->nbJours ?? 0);
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): static
    {
        $this->motif = $motif;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getValideur(): ?Invite
    {
        return $this->valideur;
    }

    public function setValideur(?Invite $valideur): static
    {
        $this->valideur = $valideur;

        return $this;
    }

    public function getDateDecision(): ?\DateTimeImmutable
    {
        return $this->dateDecision;
    }

    public function setDateDecision(?\DateTimeImmutable $dateDecision): static
    {
        $this->dateDecision = $dateDecision;

        return $this;
    }

    public function getCommentaireDecision(): ?string
    {
        return $this->commentaireDecision;
    }

    public function setCommentaireDecision(?string $commentaireDecision): static
    {
        $this->commentaireDecision = $commentaireDecision;

        return $this;
    }

    public function getOrigine(): string
    {
        return $this->origine;
    }

    public function setOrigine(string $origine): static
    {
        $this->origine = $origine;

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
            $document->setDemandeConge($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            if ($document->getDemandeConge() === $this) {
                $document->setDemandeConge(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, HistoriqueDemande>
     */
    public function getHistoriques(): Collection
    {
        return $this->historiques;
    }

    public function addHistorique(HistoriqueDemande $historique): static
    {
        if (!$this->historiques->contains($historique)) {
            $this->historiques->add($historique);
            $historique->setDemande($this);
        }

        return $this;
    }

    // ── Lectures dérivées, sans effet de bord ────────────────────────────────────────

    /** Exercice de rattachement : l'année civile de la date de DÉBUT. */
    public function getExercice(): ?int
    {
        return $this->dateDebut !== null ? (int) $this->dateDebut->format('Y') : null;
    }

    /** La demande pèse-t-elle sur le compteur ? Seuls les types décomptés le font. */
    public function estDecomptee(): bool
    {
        return $this->typeAbsence !== null && $this->typeAbsence->isDecompte();
    }

    /** La demande est-elle encore susceptible d'évoluer ? */
    public function estActive(): bool
    {
        return in_array($this->statut, [self::STATUT_SOUMISE, self::STATUT_APPROUVEE], true);
    }

    /** L'absence a-t-elle déjà commencé à la date donnée ? Gouverne RG-09. */
    public function aCommence(\DateTimeInterface $aLaDate): bool
    {
        return $this->dateDebut !== null && $this->dateDebut <= $aLaDate;
    }

    public function __toString(): string
    {
        return sprintf(
            'Congé %s → %s',
            $this->dateDebut?->format('d/m/Y') ?? '?',
            $this->dateFin?->format('d/m/Y') ?? '?',
        );
    }
}
