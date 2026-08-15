<?php

namespace App\Entity;

use App\Repository\AssistantTacheRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une question posée à Ket, en attente d'être traitée — ou en cours, ou finie.
 *
 * POURQUOI UNE TABLE ALORS QUE MESSENGER EN A DÉJÀ UNE. Les deux ne portent pas
 * la même chose. `messenger_messages` transporte un SIGNAL (« il y a du travail
 * sur la conversation 42 ») : son corps est sérialisé, il n'est indexé par rien
 * de métier, et il disparaît dès que le worker l'a consommé. Ici on porte l'ÉTAT
 * (« où en est la question n°17 »), qui doit rester interrogeable par
 * conversation, survivre à la consommation de l'enveloppe, et se relire après un
 * rechargement de page. Deux rôles, deux tables.
 *
 * L'ORDRE EST LE MÉTIER. Les tâches d'une même conversation se drainent par id
 * croissant, c'est-à-dire dans l'ordre d'acceptation — donc dans l'ordre où
 * l'utilisateur a tapé. C'est ce qui garantit qu'une rafale de trois messages
 * produit exactement ce que produiraient trois envois séquentiels : la réponse à
 * la question n est dans le fil avant que la question n+1 ne soit traitée.
 */
#[ORM\Entity(repositoryClass: AssistantTacheRepository::class)]
#[ORM\Index(name: 'idx_tache_file', columns: ['conversation_id', 'statut', 'id'])]
class AssistantTache
{
    /** Acceptée, pas encore prise en charge. */
    public const STATUT_EN_ATTENTE = 'en_attente';

    /** Un worker la traite en ce moment. */
    public const STATUT_EN_COURS = 'en_cours';

    /** Le moteur a répondu — y compris par une excuse : la réponse existe. */
    public const STATUT_TERMINEE = 'terminee';

    /**
     * Le TRAITEMENT lui-même a échoué (le worker a explosé), et il n'y a donc
     * aucune réponse. À distinguer d'une panne du moteur, qui produit une
     * réponse d'excuse persistée et laisse la tâche « terminee ».
     */
    public const STATUT_ECHOUEE = 'echouee';

    /** Statuts qui laissent encore quelque chose à attendre. */
    public const STATUTS_OUVERTS = [self::STATUT_EN_ATTENTE, self::STATUT_EN_COURS];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AssistantConversation $conversation = null;

    /**
     * LA QUESTION EST PORTÉE PAR LA TÂCHE, PAS ENCORE PAR LE FIL.
     *
     * C'est le point le plus contre-intuitif de tout le dispositif, et il est
     * imposé par une contrainte très concrète : le fil d'une conversation est
     * ordonné par identifiant croissant (AssistantConversation::$messages,
     * OrderBy id ASC). Si les trois questions d'une rafale étaient persistées
     * dès l'acceptation, elles prendraient les identifiants 1, 2, 3 et les trois
     * réponses les identifiants 4, 5, 6 : le fil deviendrait « Q1 Q2 Q3 A1 A2 A3 ».
     *
     * Ce n'est pas qu'un problème d'affichage. En traitant Q2, le moteur lirait
     * un historique où sa réponse à Q1 arrive APRÈS Q2, et surtout
     * AiRequest::lastUserMessage() lui désignerait Q3 — il répondrait trois fois
     * à la dernière question. Mesuré par AssistantRafaleTest avant correction.
     *
     * En créant le message au moment du drainage, son identifiant se place juste
     * avant celui de sa réponse, et le fil s'entrelace de lui-même : « Q1 A1 Q2
     * A2 Q3 A3 » — exactement ce que produiraient trois envois séquentiels.
     *
     * Les champs ci-dessous sont donc l'INSTANTANÉ pris à l'envoi, conservé le
     * temps de l'attente. Ils sont recopiés tels quels dans le message au
     * drainage : la sémantique « immuable à l'envoi » est préservée, seul le
     * moment de l'écriture change.
     */
    #[ORM\Column(type: 'text')]
    private string $contenu = '';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $contexteObjets = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fichiersJoints = null;

    /** Le message cité (« Répondre » du menu de bulle), le cas échéant. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AssistantMessage $repondA = null;

    /** La question, une fois matérialisée dans le fil par le drainage. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?AssistantMessage $messageUtilisateur = null;

    /** La réponse, une fois qu'elle existe. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AssistantMessage $messageAssistant = null;

    #[ORM\Column(length: 16)]
    private string $statut = self::STATUT_EN_ATTENTE;

    /**
     * Où en est le moteur, au format exact qu'émet JournalTokens
     * (`{cle, tokensEtape, tokensCumul}`) — donc exactement celui que sait déjà
     * lire le navigateur. Aucune traduction entre les deux bouts.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $etape = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $erreur = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getContenu(): string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): self
    {
        $this->contenu = $contenu;
        return $this;
    }

    public function getContexteObjets(): ?array
    {
        return $this->contexteObjets;
    }

    public function setContexteObjets(?array $contexteObjets): self
    {
        $this->contexteObjets = $contexteObjets;
        return $this;
    }

    public function getFichiersJoints(): ?array
    {
        return $this->fichiersJoints;
    }

    public function setFichiersJoints(?array $fichiersJoints): self
    {
        $this->fichiersJoints = $fichiersJoints;
        return $this;
    }

    public function getRepondA(): ?AssistantMessage
    {
        return $this->repondA;
    }

    public function setRepondA(?AssistantMessage $repondA): self
    {
        $this->repondA = $repondA;
        return $this;
    }

    public function getMessageUtilisateur(): ?AssistantMessage
    {
        return $this->messageUtilisateur;
    }

    public function setMessageUtilisateur(?AssistantMessage $message): self
    {
        $this->messageUtilisateur = $message;
        return $this;
    }

    public function getMessageAssistant(): ?AssistantMessage
    {
        return $this->messageAssistant;
    }

    public function setMessageAssistant(?AssistantMessage $message): self
    {
        $this->messageAssistant = $message;
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

    public function getEtape(): ?array
    {
        return $this->etape;
    }

    public function setEtape(?array $etape): self
    {
        $this->etape = $etape;
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;
        return $this;
    }

    /** Reste-t-il quelque chose à attendre de cette tâche ? */
    public function estOuverte(): bool
    {
        return in_array($this->statut, self::STATUTS_OUVERTS, true);
    }

    public function estTerminee(): bool
    {
        return $this->statut === self::STATUT_TERMINEE;
    }
}
