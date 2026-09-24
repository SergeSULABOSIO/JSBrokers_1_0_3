<?php

namespace App\Entity;

use App\Repository\KetReglageJournalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * QUI A CHANGÉ QUOI, QUAND, ET POURQUOI — sur les réglages de Ket.
 *
 * ── LE MOTIF N'EST PAS FACULTATIF ───────────────────────────────────────────
 * Un journal qui dit « chronologie désactivé le 24/09 par S. Sula » ne sert à rien
 * six mois plus tard : on sait CE qui a changé, jamais si c'était délibéré. La
 * colonne est donc NOT NULL, et l'écran refuse d'enregistrer sans elle. C'est la
 * seule chose qui rende le retour arrière décidable — sans motif, on ne peut que
 * deviner s'il faut défaire.
 *
 * ── PAS D'AuditableTrait, ET C'EST LE PIÈGE À ÉVITER ────────────────────────
 * Le trait d'audit du projet impose `entreprise` NOT NULL : il date et signe ce qui
 * appartient à UN cabinet. Ce journal-ci est un journal de PLATEFORME — il n'a pas
 * d'entreprise, et lui en inventer une serait mentir sur la portée du changement,
 * qui vaut justement pour tous les cabinets à la fois.
 *
 * ── L'AUTEUR PEUT DISPARAÎTRE, LA LIGNE RESTE ───────────────────────────────
 * `auteur` est nullable et sa suppression met la clé à NULL plutôt que d'emporter
 * la ligne : un collaborateur qui quitte Joseara n'efface pas l'histoire des
 * réglages de la plateforme. Le nom est donc recopié dans `auteurNom` au moment du
 * changement — c'est lui qu'affiche l'historique, et il survit au compte.
 */
#[ORM\Entity(repositoryClass: KetReglageJournalRepository::class)]
#[ORM\Index(columns: ['effectue_le'], name: 'idx_ket_reglage_journal_date')]
class KetReglageJournal
{
    /** Un outil a été activé ou désactivé. */
    public const TYPE_OUTIL = 'outil';

    /** Un seuil métier a changé de valeur. */
    public const TYPE_PARAMETRE = 'parametre';

    /** Tout a été remis aux valeurs du code. */
    public const TYPE_REINITIALISATION = 'reinitialisation';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * L'élément touché, préfixé par sa famille : `outil:chronologie`,
     * `parametre:vigie.horizon_jours`. Le préfixe évite qu'un outil et un paramètre
     * portant le même nom se confondent dans l'historique.
     */
    #[ORM\Column(length: 120)]
    private string $element = '';

    #[ORM\Column(length: 20)]
    private string $type = self::TYPE_OUTIL;

    /** Valeur avant le changement, en clair — « actif », « 30 », … */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ancienneValeur = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nouvelleValeur = null;

    /** POURQUOI. Obligatoire : cf. le docblock de classe. */
    #[ORM\Column(type: Types::TEXT)]
    private string $motif = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $auteur = null;

    /** Recopié au moment du changement : il survit à la suppression du compte. */
    #[ORM\Column(length: 180)]
    private string $auteurNom = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $effectueLe;

    public function __construct()
    {
        $this->effectueLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getElement(): string
    {
        return $this->element;
    }

    public function setElement(string $element): static
    {
        $this->element = $element;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getAncienneValeur(): ?string
    {
        return $this->ancienneValeur;
    }

    public function setAncienneValeur(?string $ancienneValeur): static
    {
        $this->ancienneValeur = $ancienneValeur;

        return $this;
    }

    public function getNouvelleValeur(): ?string
    {
        return $this->nouvelleValeur;
    }

    public function setNouvelleValeur(?string $nouvelleValeur): static
    {
        $this->nouvelleValeur = $nouvelleValeur;

        return $this;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): static
    {
        $this->motif = $motif;

        return $this;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): static
    {
        $this->auteur = $auteur;
        if ($auteur !== null) {
            $this->auteurNom = $auteur->getNom() ?: (string) $auteur->getEmail();
        }

        return $this;
    }

    public function getAuteurNom(): string
    {
        return $this->auteurNom;
    }

    public function setAuteurNom(string $auteurNom): static
    {
        $this->auteurNom = $auteurNom;

        return $this;
    }

    public function getEffectueLe(): \DateTimeImmutable
    {
        return $this->effectueLe;
    }

    public function setEffectueLe(\DateTimeImmutable $effectueLe): static
    {
        $this->effectueLe = $effectueLe;

        return $this;
    }

    /** Le nom technique, sans son préfixe de famille. */
    public function nomCourt(): string
    {
        $position = strpos($this->element, ':');

        return $position === false ? $this->element : substr($this->element, $position + 1);
    }
}
