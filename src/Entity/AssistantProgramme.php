<?php

namespace App\Entity;

use App\Repository\AssistantProgrammeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * PROGRAMME de Ket : une SÉRIE ordonnée de plans d'écriture que l'utilisateur
 * valide l'un après l'autre, du premier au dernier, sans qu'aucun ne soit oublié.
 *
 * Pourquoi une entité et non un simple champ de meta : après l'exécution d'un
 * plan, la boucle conversationnelle est ROMPUE (l'endpoint d'exécution est
 * hors-LLM et ne crée aucun message). Le modèle, lui, ne « se souvient » d'une
 * série qu'à travers sa prose — d'où le symptôme constaté en production :
 * Ket exécutait le premier plan d'une série de trois, s'arrêtait, puis affirmait
 * que les trois étaient enregistrés. La mémoire de la série doit donc vivre
 * ailleurs que dans le modèle : ici, en base, avec une référence unique et un
 * statut par étape — ce qui rend possible le RAPPORT FINAL vérifié en base.
 *
 * Le plan de chaque étape reste stocké là où il l'a toujours été
 * (AssistantMessage.meta['mutationPlan']) : l'étape ne fait que pointer son
 * message. Tout le circuit d'exécution existant (re-validation, étendue,
 * solvabilité, mot de passe, F5) est donc inchangé.
 */
#[ORM\Entity(repositoryClass: AssistantProgrammeRepository::class)]
#[ORM\HasLifecycleCallbacks]
class AssistantProgramme
{
    /** Des étapes restent à trancher : c'est le programme « courant » du fil. */
    public const STATUT_EN_COURS = 'en_cours';

    /** Toutes les étapes ont été tranchées (exécutées, annulées ou impossibles). */
    public const STATUT_TERMINE = 'termine';

    /** L'utilisateur a stoppé la série avant la fin (bouton « Interrompre »). */
    public const STATUT_INTERROMPU = 'interrompu';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Référence unique et LISIBLE de la mission (PRG-AAAAMMJJ-XXXX). C'est elle
     * que Ket cite dans le fil et dans le rapport final — l'utilisateur doit
     * pouvoir désigner un programme sans connaître les identifiants techniques.
     */
    #[ORM\Column(length: 24, unique: true)]
    private ?string $reference = null;

    #[ORM\ManyToOne(targetEntity: AssistantConversation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AssistantConversation $conversation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entreprise $entreprise = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Invite $invite = null;

    /** Ce que l'utilisateur a demandé, dans ses termes (sert d'entête au rapport). */
    #[ORM\Column(type: 'text')]
    private ?string $objectif = null;

    #[ORM\Column(length: 16)]
    private string $statut = self::STATUT_EN_COURS;

    /** @var Collection<int, AssistantProgrammeEtape> */
    #[ORM\OneToMany(targetEntity: AssistantProgrammeEtape::class, mappedBy: 'programme', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $etapes;

    /**
     * Rapport final figé (état des lieux vérifié en base). Stocké pour qu'il
     * survive au rechargement de page et reste opposable : c'est le compte rendu
     * de mission, pas une reformulation du modèle.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rapport = null;

    /**
     * Programme dont celui-ci corrige les écarts (null pour un programme
     * ordinaire). Permet de remonter la chaîne « mission → correction ».
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AssistantProgramme $corrige = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct()
    {
        $this->etapes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getConversation(): ?AssistantConversation
    {
        return $this->conversation;
    }

    public function setConversation(?AssistantConversation $conversation): self
    {
        $this->conversation = $conversation;

        return $this;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): self
    {
        $this->entreprise = $entreprise;

        return $this;
    }

    public function getInvite(): ?Invite
    {
        return $this->invite;
    }

    public function setInvite(?Invite $invite): self
    {
        $this->invite = $invite;

        return $this;
    }

    public function getObjectif(): ?string
    {
        return $this->objectif;
    }

    public function setObjectif(string $objectif): self
    {
        $this->objectif = $objectif;

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

    public function estEnCours(): bool
    {
        return $this->statut === self::STATUT_EN_COURS;
    }

    /** @return Collection<int, AssistantProgrammeEtape> */
    public function getEtapes(): Collection
    {
        return $this->etapes;
    }

    public function addEtape(AssistantProgrammeEtape $etape): self
    {
        if (!$this->etapes->contains($etape)) {
            $this->etapes->add($etape);
            $etape->setProgramme($this);
        }

        return $this;
    }

    public function removeEtape(AssistantProgrammeEtape $etape): self
    {
        if ($this->etapes->removeElement($etape) && $etape->getProgramme() === $this) {
            $etape->setProgramme(null);
        }

        return $this;
    }

    /**
     * PROCHAINE étape à présenter : la première qui attend encore d'être préparée.
     * Source unique de l'avancement — aucun compteur à tenir à jour en parallèle,
     * donc aucune dérive possible entre « où on en est » et l'état réel des étapes.
     */
    public function prochaineEtape(): ?AssistantProgrammeEtape
    {
        foreach ($this->etapes as $etape) {
            if ($etape->getStatut() === AssistantProgrammeEtape::STATUT_EN_ATTENTE) {
                return $etape;
            }
        }

        return null;
    }

    /** Étape actuellement PROPOSÉE à l'utilisateur (barre de décision affichée). */
    public function etapeProposee(): ?AssistantProgrammeEtape
    {
        foreach ($this->etapes as $etape) {
            if ($etape->getStatut() === AssistantProgrammeEtape::STATUT_PROPOSEE) {
                return $etape;
            }
        }

        return null;
    }

    public function nbEtapes(): int
    {
        return $this->etapes->count();
    }

    /** Nombre d'étapes déjà tranchées (quel qu'en soit le sort). */
    public function nbTranchees(): int
    {
        $n = 0;
        foreach ($this->etapes as $etape) {
            if ($etape->estTranchee()) {
                ++$n;
            }
        }

        return $n;
    }

    public function getRapport(): ?array
    {
        return $this->rapport;
    }

    public function setRapport(?array $rapport): self
    {
        $this->rapport = $rapport;

        return $this;
    }

    public function getCorrige(): ?self
    {
        return $this->corrige;
    }

    public function setCorrige(?self $corrige): self
    {
        $this->corrige = $corrige;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): self
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable('now');
    }
}
