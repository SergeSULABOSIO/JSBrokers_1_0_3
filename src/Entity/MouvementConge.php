<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\MouvementCongeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @file Le journal des congés — SOURCE DE VÉRITÉ du compteur d'un agent.
 * @description Il n'existe pas de colonne « solde » quelque part : le solde est la somme
 * de ces lignes, et rien d'autre. Un forfait recopié dans une seconde table finirait par
 * diverger de son journal ; on ne l'écrit donc nulle part.
 *
 * UN MOUVEMENT EST IMMUABLE. Une erreur se corrige par un mouvement inverse motivé,
 * jamais par une modification ou une suppression : l'historique doit pouvoir être relu
 * des années plus tard et donner le même solde.
 *
 * Les lignes ne sont écrites que par CongeTransitionSubscriber, à partir d'une
 * transition d'état constatée sur une demande. Aucun appelant ne les fabrique
 * directement — c'est ce qui garantit que la décision prise à l'écran et la décision
 * prise via l'assistant produisent exactement le même compteur.
 */
#[ORM\Entity(repositoryClass: MouvementCongeRepository::class)]
#[ORM\HasLifecycleCallbacks]
class MouvementConge implements OwnerAwareInterface
{
    use AuditableTrait;

    /** Forfait crédité à l'ouverture de l'exercice. */
    public const NATURE_DOTATION = 'DOTATION';
    /** Reliquat de l'exercice précédent, recrédité sur le nouvel exercice. */
    public const NATURE_REPORT = 'REPORT';
    /** Jours consommés par une demande approuvée. Quantité NÉGATIVE. */
    public const NATURE_PRISE = 'PRISE';
    /** Recrédit après annulation d'une demande approuvée. Quantité POSITIVE. */
    public const NATURE_ANNULATION = 'ANNULATION';
    /** Correction manuelle motivée par un valideur. Signe libre. */
    public const NATURE_AJUSTEMENT = 'AJUSTEMENT';
    /** Régularisation au départ d'un agent. Signe libre. */
    public const NATURE_REGULARISATION_SORTIE = 'REGULARISATION_SORTIE';

    /**
     * Natures qui CRÉDITENT le compteur (l'acquis de l'exercice). La PRISE, elle, est
     * une consommation. Source unique lue par CalculateurSolde : ajouter une nature sans
     * la classer ici la rendrait invisible du solde, en silence.
     */
    public const NATURES_ACQUISES = [
        self::NATURE_DOTATION,
        self::NATURE_REPORT,
        self::NATURE_ANNULATION,
        self::NATURE_AJUSTEMENT,
        self::NATURE_REGULARISATION_SORTIE,
    ];

    public const NATURES = [
        self::NATURE_DOTATION => 'Dotation',
        self::NATURE_REPORT => 'Report',
        self::NATURE_PRISE => 'Prise',
        self::NATURE_ANNULATION => 'Annulation',
        self::NATURE_AJUSTEMENT => 'Ajustement',
        self::NATURE_REGULARISATION_SORTIE => 'Régularisation de sortie',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    /** Le collaborateur dont le compteur bouge. */
    #[ORM\ManyToOne(inversedBy: 'mouvementsConge')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Invite $agent = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $exercice = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?TypeAbsence $typeAbsence = null;

    #[ORM\Column(length: 30)]
    #[Groups(['list:read'])]
    private ?string $nature = null;

    /** Quantité SIGNÉE, en jours. Une prise est négative, une dotation positive. */
    #[ORM\Column(type: 'decimal', precision: 6, scale: 1)]
    #[Groups(['list:read'])]
    private ?string $quantite = null;

    /** La demande à l'origine du mouvement. Null pour une dotation ou un ajustement. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DemandeConge $demande = null;

    /** L'humain à l'origine du mouvement — jamais l'assistant (RG-22). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Invite $auteur = null;

    #[ORM\Column(length: 10)]
    #[Groups(['list:read'])]
    private string $origine = DemandeConge::ORIGINE_UI;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $commentaire = null;

    #[Groups(['list:read'])]
    public ?string $agentNom = null;

    #[Groups(['list:read'])]
    public ?string $natureLibelle = null;

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

    public function getExercice(): ?int
    {
        return $this->exercice;
    }

    public function setExercice(?int $exercice): static
    {
        $this->exercice = $exercice;

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

    public function getNature(): ?string
    {
        return $this->nature;
    }

    public function setNature(?string $nature): static
    {
        $this->nature = $nature;

        return $this;
    }

    public function getQuantite(): ?string
    {
        return $this->quantite;
    }

    public function setQuantite(?string $quantite): static
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function quantiteFloat(): float
    {
        return (float) ($this->quantite ?? 0);
    }

    public function getDemande(): ?DemandeConge
    {
        return $this->demande;
    }

    public function setDemande(?DemandeConge $demande): static
    {
        $this->demande = $demande;

        return $this;
    }

    public function getAuteur(): ?Invite
    {
        return $this->auteur;
    }

    public function setAuteur(?Invite $auteur): static
    {
        $this->auteur = $auteur;

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

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): static
    {
        $this->commentaire = $commentaire;

        return $this;
    }

    public function natureLibelle(): string
    {
        return self::NATURES[$this->nature] ?? (string) $this->nature;
    }

    public function __toString(): string
    {
        return sprintf('%s %+.1f j', $this->natureLibelle(), $this->quantiteFloat());
    }
}
