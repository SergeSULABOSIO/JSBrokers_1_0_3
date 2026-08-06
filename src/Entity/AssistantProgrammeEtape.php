<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Une ÉTAPE d'un programme : un plan d'écriture à présenter, à faire valider,
 * puis à exécuter — avec sa référence unique et son statut d'exécution.
 *
 * L'étape ne PORTE pas le plan : elle porte de quoi le FABRIQUER (le nom d'un
 * outil producteur de plan + ses arguments), et pointe le message qui l'a
 * présenté une fois préparé. C'est ce qui rend l'enchaînement DÉTERMINISTE :
 * quand l'étape précédente est tranchée, le serveur rappelle lui-même l'outil de
 * la suivante — sans relancer le modèle, donc sans risque qu'il oublie une
 * étape, en recopie une déjà faite, ou en invente une.
 */
#[ORM\Entity]
#[ORM\Table(name: 'assistant_programme_etape')]
class AssistantProgrammeEtape
{
    /** Pas encore préparée : son tour n'est pas venu. */
    public const STATUT_EN_ATTENTE = 'en_attente';

    /** Plan préparé et présenté : la barre de décision attend l'utilisateur. */
    public const STATUT_PROPOSEE = 'proposee';

    /** Plan validé et écrit en base. */
    public const STATUT_EXECUTEE = 'executee';

    /** L'utilisateur a refusé cette étape (le programme continue sans elle). */
    public const STATUT_ANNULEE = 'annulee';

    /**
     * L'outil a refusé de préparer le plan (informations manquantes, blocage
     * métier, cible introuvable). Aucune écriture, et la série continue : une
     * étape infaisable ne doit jamais figer tout le programme.
     */
    public const STATUT_IMPOSSIBLE = 'impossible';

    /** L'exécution a échoué (transaction annulée). */
    public const STATUT_ECHEC = 'echec';

    /** Statuts au-delà desquels l'étape ne sera plus présentée. */
    public const STATUTS_TRANCHES = [
        self::STATUT_EXECUTEE,
        self::STATUT_ANNULEE,
        self::STATUT_IMPOSSIBLE,
        self::STATUT_ECHEC,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AssistantProgramme::class, inversedBy: 'etapes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AssistantProgramme $programme = null;

    /** Rang dans la série (1-based) : l'ordre est celui demandé par l'utilisateur. */
    #[ORM\Column]
    private int $ordre = 1;

    /** Référence unique et lisible de l'étape : « PRG-20260806-3F2A/02 ». */
    #[ORM\Column(length: 32)]
    private ?string $reference = null;

    /** Ce que cette étape fait, en clair (« Tranche 64 — CASH MANAGEMENT SOLUTIONS »). */
    #[ORM\Column(length: 255)]
    private ?string $libelle = null;

    /** Nom technique de l'outil producteur de plan (allowlist : OutilsDePlan). */
    #[ORM\Column(length: 64)]
    private ?string $outil = null;

    /** Arguments à passer à l'outil au moment de préparer cette étape. */
    #[ORM\Column(type: 'json')]
    private array $arguments = [];

    #[ORM\Column(length: 16)]
    private string $statut = self::STATUT_EN_ATTENTE;

    /**
     * Message assistant qui a présenté le plan de cette étape. C'est lui qui
     * porte meta['mutationPlan'] : l'endpoint d'exécution existant n'a donc
     * besoin d'aucune connaissance du programme pour faire son travail.
     */
    #[ORM\ManyToOne(targetEntity: AssistantMessage::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AssistantMessage $message = null;

    /** Journal d'exécution renvoyé par le moteur de mutation (liste vraie des écritures). */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $journal = null;

    /** Résultat de la relecture en base (existence, champs, effet métier). */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $verification = null;

    /** Raison d'un refus / d'un échec, telle qu'elle sera citée dans le rapport. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $erreur = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgramme(): ?AssistantProgramme
    {
        return $this->programme;
    }

    public function setProgramme(?AssistantProgramme $programme): self
    {
        $this->programme = $programme;

        return $this;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): self
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = mb_substr($libelle, 0, 255);

        return $this;
    }

    public function getOutil(): ?string
    {
        return $this->outil;
    }

    public function setOutil(string $outil): self
    {
        $this->outil = $outil;

        return $this;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function setArguments(array $arguments): self
    {
        $this->arguments = $arguments;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function estTranchee(): bool
    {
        return in_array($this->statut, self::STATUTS_TRANCHES, true);
    }

    public function getMessage(): ?AssistantMessage
    {
        return $this->message;
    }

    public function setMessage(?AssistantMessage $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function getJournal(): ?array
    {
        return $this->journal;
    }

    public function setJournal(?array $journal): self
    {
        $this->journal = $journal;

        return $this;
    }

    public function getVerification(): ?array
    {
        return $this->verification;
    }

    public function setVerification(?array $verification): self
    {
        $this->verification = $verification;

        return $this;
    }

    public function getErreur(): ?string
    {
        return $this->erreur;
    }

    public function setErreur(?string $erreur): self
    {
        $this->erreur = $erreur;

        return $this;
    }
}
