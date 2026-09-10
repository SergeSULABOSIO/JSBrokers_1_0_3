<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\EchangeImportRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * UN CONTRÔLE D'IMPORT EN ATTENTE DE DÉCISION.
 *
 * L'importation se joue en deux temps qui ne peuvent pas tenir dans une seule requête :
 * on contrôle d'abord à blanc — gratuitement, sans rien écrire — puis l'utilisateur
 * décide, en connaissance du rapport et du coût. Entre les deux, il faut bien que le
 * travail de contrôle survive : c'est cette entité.
 *
 * ⚠ Elle porte le RAPPORT, pas les données déposées. Le fichier reste sur disque et
 * n'est relu qu'à la confirmation : stocker les lignes ici reviendrait à recopier tout
 * un cabinet dans une colonne JSON, et à multiplier les endroits où des données
 * personnelles attendent qu'on les oublie.
 *
 * Elle EXPIRE, et c'est le seul moyen d'y toucher : un contrôle abandonné disparaît de
 * lui-même, avec le fichier qu'il désigne. Une occurrence, elle, ne s'efface jamais —
 * les deux entités n'ont pas la même mémoire parce qu'elles n'ont pas le même rôle.
 */
#[ORM\Entity(repositoryClass: EchangeImportRunRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_echange_import_run_entreprise', columns: ['entreprise_id'])]
class EchangeImportRun implements OwnerAwareInterface
{
    use AuditableTrait;

    /** Contrôle en cours d'exécution (passe 1 et 2). */
    public const STATUT_CONTROLE = 'CONTROLE';
    /** Contrôle terminé sans erreur bloquante : la décision appartient à l'utilisateur. */
    public const STATUT_EN_ATTENTE_CONFIRMATION = 'EN_ATTENTE_CONFIRMATION';
    /** Écriture en cours (passe 3). */
    public const STATUT_EN_COURS = 'EN_COURS';
    public const STATUT_TERMINE = 'TERMINE';
    /** Contrôle ou écriture en échec — dans les deux cas, la base est intacte. */
    public const STATUT_ECHEC = 'ECHEC';
    public const STATUT_ANNULE = 'ANNULE';

    public const STATUTS = [
        self::STATUT_CONTROLE => 'Contrôle en cours',
        self::STATUT_EN_ATTENTE_CONFIRMATION => 'En attente de confirmation',
        self::STATUT_EN_COURS => 'Importation en cours',
        self::STATUT_TERMINE => 'Terminé',
        self::STATUT_ECHEC => 'Échec',
        self::STATUT_ANNULE => 'Annulé',
    ];

    /** Durée de vie d'un contrôle non confirmé. Au-delà, le dépôt est réputé abandonné. */
    public const DUREE_DE_VIE_HEURES = 24;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nomFichier = null;

    /** Chemin de stockage du dépôt, hors `public/` : le fichier ne doit jamais être servi tel quel. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $cheminFichier = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $empreinteFichier = null;

    #[ORM\Column(length: 32)]
    #[Groups(['list:read'])]
    private string $statut = self::STATUT_CONTROLE;

    /**
     * Rapport du contrôle : anomalies localisées (feuille, ligne, colonne, gravité) et
     * synthèse par entité. C'est ce que l'écran affiche, ce que le classeur `_RAPPORT`
     * restitue et ce que l'assistant raconte — une seule source pour les trois.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $rapport = [];

    /**
     * L'utilisateur a-t-il explicitement autorisé les suppressions ? Désactivé par
     * défaut : une colonne `_action` mal recopiée ne doit pas pouvoir vider une feuille.
     */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private bool $suppressionsAutorisees = false;

    /**
     * Données retenues au dépôt, quand l'utilisateur n'a pas tout pris. Vide = tout ce que
     * le fichier contient.
     *
     * ⚠ CE CHOIX DOIT SURVIVRE JUSQU'À LA CONFIRMATION. L'écriture RECONTRÔLE le fichier
     * — c'est ce qui la protège d'un état devenu faux entre-temps — et referait donc
     * l'inventaire complet des feuilles. Sans cette mémoire, confirmer un import
     * volontairement restreint à la production réécrirait aussi les taxes et les monnaies
     * que l'utilisateur avait écartées, sans que rien ne le lui dise.
     *
     * @var string[]
     */
    #[ORM\Column(type: Types::JSON)]
    private array $donnees = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $expireLe = null;

    /**
     * OÙ EN EST LE TRAVAIL — la ligne du fichier après laquelle il reste tout à faire.
     *
     * ⚠ SANS CURSEUR, UN IMPORT NE PEUT PAS ÊTRE REPRIS, et c'est ce qui imposait de tout
     * faire dans une seule requête. Or le contrôle à blanc retient plusieurs mégaoctets
     * par ligne, sans les rendre : la requête mourait avant la fin sur un portefeuille
     * réel, et le message accusait la base à la place de PHP.
     *
     * Le travail avance donc par PALIERS, chacun dans un processus neuf. Ce nombre est ce
     * qui permet au suivant de savoir où reprendre — et à l'écran de dire où l'on en est
     * même quand personne ne regarde.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $curseur = 0;

    /**
     * Ce que le fichier porte de lignes, connu dès la première lecture.
     *
     * Un pourcentage sans dénominateur n'est pas une progression, c'est une animation.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $totalLignes = 0;

    /**
     * LIGNES DE FRANCHISE RÉELLEMENT CONSOMMÉES PAR CE RUN — le compteur de la gratuité.
     *
     * ⚠ IL VIT ICI, ET NON SUR L'OCCURRENCE, parce que l'occurrence n'est écrite qu'à la
     * fin d'une écriture RÉUSSIE. Un import interrompu — solde épuisé, onglet fermé,
     * processus tué — laisse pourtant derrière lui des paliers déjà commités, donc des
     * lignes gratuites bel et bien écrites. Les compter sur l'occurrence les aurait rendues
     * invisibles : il aurait suffi de redéposer et de faire échouer pour obtenir une
     * franchise sans fin.
     *
     * ⚠ ET IL S'INCRÉMENTE DANS LA TRANSACTION DU PALIER, jamais après elle : le compteur
     * est ainsi atomique avec les écritures qu'il paie. Si le palier est annulé, rien n'a
     * été écrit et rien n'a été décompté.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $lignesFranchisees = 0;

    /**
     * DEPUIS QUAND UN PALIER TRAVAILLE — `null` quand personne n'y touche.
     *
     * ⚠ C'EST UN VERROU AUTANT QU'UN SIGNE DE VIE, et les deux rôles n'en font qu'un.
     *
     * Verrou : deux paliers simultanés sur le même contrôle traiteraient la même fenêtre
     * de lignes dans deux transactions séparées — chacune créerait « son » client, et
     * l'idempotence, qui ne voit que ce qui est COMMITÉ, n'y pourrait rien. C'est
     * exactement le doublon que toute cette reprise existe pour empêcher.
     *
     * Signe de vie : un import dont le processus meurt en plein palier — onglet fermé,
     * worker arrêté, PHP à court de mémoire — restait « en cours » pour toujours : plus
     * confirmable, plus annulable, invisible du dépôt suivant. L'utilisateur venait de
     * cliquer « Confirmer » et n'avait aucun moyen de savoir si son portefeuille était
     * repris. Une date ancienne le dit ; une colonne vide dit qu'on attend un pousseur.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $travailDepuis = null;

    /**
     * Au-delà, le palier est réputé mort et le verrou peut être repris.
     *
     * Un processus tué net ne relâche rien : sans péremption, le contrôle resterait gelé
     * pour toujours et l'utilisateur n'aurait aucun moyen de s'en sortir. Cinq minutes,
     * soit bien plus que le palier le plus lent, et bien moins qu'une pause déjeuner.
     */
    public const TRAVAIL_PEREMPTION_SECONDES = 300;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCurseur(): int
    {
        return $this->curseur;
    }

    public function setCurseur(int $curseur): static
    {
        $this->curseur = max(0, $curseur);

        return $this;
    }

    public function getTotalLignes(): int
    {
        return $this->totalLignes;
    }

    public function setTotalLignes(int $totalLignes): static
    {
        $this->totalLignes = max(0, $totalLignes);

        return $this;
    }

    public function getLignesFranchisees(): int
    {
        return $this->lignesFranchisees;
    }

    /**
     * ⚠ ON AJOUTE, ON NE POSE PAS. Un run avance par paliers : chacun ajoute les lignes
     * qu'il vient d'écrire gratuitement. Écraser reviendrait à ne compter que le dernier.
     */
    public function ajouterLignesFranchisees(int $lignes): static
    {
        $this->lignesFranchisees += max(0, $lignes);

        return $this;
    }

    public function getTravailDepuis(): ?\DateTimeImmutable
    {
        return $this->travailDepuis;
    }

    public function setTravailDepuis(?\DateTimeImmutable $travailDepuis): static
    {
        $this->travailDepuis = $travailDepuis;

        return $this;
    }

    /**
     * Un palier travaille-t-il en ce moment ?
     *
     * Un statut ne suffit pas : il dit ce qu'on a VOULU faire, pas ce qui se passe. Cette
     * date-ci n'est posée que par un processus qui travaille vraiment, et retirée quand il
     * a fini — un palier mort la laisse derrière lui, et c'est ainsi qu'on le reconnaît.
     */
    public function travailEnCours(?\DateTimeImmutable $maintenant = null): bool
    {
        if ($this->travailDepuis === null) {
            return false;
        }

        $maintenant ??= new \DateTimeImmutable('now');

        return ($maintenant->getTimestamp() - $this->travailDepuis->getTimestamp())
            <= self::TRAVAIL_PEREMPTION_SECONDES;
    }

    /**
     * Le travail est-il resté en plan ?
     *
     * ⚠ C'EST LA QUESTION QUE L'ÉCRAN POSE APRÈS UN INCIDENT. Un palier commencé qui n'a
     * jamais rendu la main a laissé sa date derrière lui : au-delà de la péremption, il
     * n'y a plus personne au bout, et le dire vaut mieux que d'afficher « en cours »
     * jusqu'à la fin des temps.
     */
    public function travailAbandonne(?\DateTimeImmutable $maintenant = null): bool
    {
        if (!in_array($this->statut, [self::STATUT_CONTROLE, self::STATUT_EN_COURS], true)) {
            return false;
        }

        return $this->travailDepuis !== null && !$this->travailEnCours($maintenant);
    }

    /** Reste-t-il des lignes à traiter dans la phase en cours ? */
    public function resteAFaire(): bool
    {
        return $this->curseur < $this->totalLignes;
    }

    public function getNomFichier(): ?string
    {
        return $this->nomFichier;
    }

    public function setNomFichier(string $nomFichier): static
    {
        $this->nomFichier = $nomFichier;

        return $this;
    }

    public function getCheminFichier(): ?string
    {
        return $this->cheminFichier;
    }

    public function setCheminFichier(?string $cheminFichier): static
    {
        $this->cheminFichier = $cheminFichier;

        return $this;
    }

    public function getEmpreinteFichier(): ?string
    {
        return $this->empreinteFichier;
    }

    public function setEmpreinteFichier(?string $empreinteFichier): static
    {
        $this->empreinteFichier = $empreinteFichier;

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

    public function getStatutLibelle(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    /** @return array<string, mixed> */
    public function getRapport(): array
    {
        return $this->rapport;
    }

    /** @param array<string, mixed> $rapport */
    public function setRapport(array $rapport): static
    {
        $this->rapport = $rapport;

        return $this;
    }

    public function isSuppressionsAutorisees(): bool
    {
        return $this->suppressionsAutorisees;
    }

    public function setSuppressionsAutorisees(bool $suppressionsAutorisees): static
    {
        $this->suppressionsAutorisees = $suppressionsAutorisees;

        return $this;
    }

    /** @return string[] */
    public function getDonnees(): array
    {
        return $this->donnees;
    }

    /** @param string[] $donnees */
    public function setDonnees(array $donnees): static
    {
        $this->donnees = array_values($donnees);

        return $this;
    }

    public function getExpireLe(): ?\DateTimeImmutable
    {
        return $this->expireLe;
    }

    public function setExpireLe(\DateTimeImmutable $expireLe): static
    {
        $this->expireLe = $expireLe;

        return $this;
    }

    /**
     * Seul état depuis lequel une écriture peut être déclenchée. Vérifié au moment
     * d'exécuter, jamais seulement à l'affichage : entre le rendu de l'écran et le clic,
     * le contrôle a pu expirer, être annulé, ou avoir déjà été confirmé ailleurs.
     */
    public function estConfirmable(?\DateTimeImmutable $maintenant = null): bool
    {
        if ($this->statut !== self::STATUT_EN_ATTENTE_CONFIRMATION) {
            return false;
        }

        $maintenant ??= new \DateTimeImmutable('now');

        return $this->expireLe === null || $this->expireLe > $maintenant;
    }
}
