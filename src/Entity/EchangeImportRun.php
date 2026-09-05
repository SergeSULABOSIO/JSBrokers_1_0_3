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

    public function getId(): ?int
    {
        return $this->id;
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
