<?php

namespace App\Entity;

use App\Repository\ErreurApplicativeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @file Une erreur de l'application, regroupée et comptée.
 *
 * @description
 * UNE LIGNE = UN DÉFAUT, pas une occurrence. C'est tout l'intérêt de cette
 * table : une erreur rencontrée mille fois reste UNE ligne à traiter, avec un
 * compteur qui en dit l'ampleur. Mille lignes identiques ne se priorisent pas,
 * elles se subissent.
 *
 * ── LE REGROUPEMENT ─────────────────────────────────────────────────────────
 * L'empreinte ne retient PAS le message, mais le TYPE et le LIEU. « Client 42
 * introuvable » et « Client 77 introuvable » sont le même défaut, à la même
 * ligne du même fichier : les compter séparément viderait le compteur de son
 * sens — et c'est le compteur qui décide de l'ordre du travail.
 *
 * ── DEUX AXES DE SOURCE, PARCE QU'ILS APPELLENT DEUX GESTES ─────────────────
 * Le CÔTÉ dit dans quel langage chercher : PHP ou JavaScript. La BRANCHE dit
 * qui est touché — un prospect sur le portail, un courtier dans son espace, ou
 * l'équipe Joseara dans la Console. Une erreur du portail empêche une
 * inscription ; la même dans la Console ne gêne que nous. Gravité technique
 * identique, urgences opposées.
 *
 * ── CE QUI N'EST PAS UNE RELATION, ET POURQUOI ──────────────────────────────
 * Le cabinet et l'utilisateur de la dernière occurrence sont stockés en TEXTE.
 * Une erreur doit survivre à la suppression du cabinet qui l'a provoquée : une
 * clé étrangère effacerait la trace au moment précis où l'on cherche à
 * comprendre ce qui s'est passé.
 *
 * Entité GLOBALE à la plateforme : aucun scoping par entreprise, comme toutes
 * les entités de la Console (Coupon, Charge, TaxeVente).
 */
#[ORM\Entity(repositoryClass: ErreurApplicativeRepository::class)]
#[ORM\Table(name: 'erreur_applicative')]
#[ORM\Index(columns: ['derniere_occurrence_at'], name: 'idx_erreur_derniere')]
#[ORM\Index(columns: ['statut'], name: 'idx_erreur_statut')]
#[ORM\HasLifecycleCallbacks]
class ErreurApplicative
{
    /** Erreur PHP : exception non rattrapée, ou appel explicite au journal. */
    public const COTE_SERVEUR = 'serveur';
    /** Erreur JavaScript remontée par le navigateur d'un utilisateur. */
    public const COTE_NAVIGATEUR = 'navigateur';

    public const COTES = [self::COTE_SERVEUR, self::COTE_NAVIGATEUR];

    /** Site vitrine, inscription, connexion : ce que voit un prospect. */
    public const BRANCHE_PORTAIL = 'portail';
    /** Espace de travail d'un cabinet de courtage. */
    public const BRANCHE_WORKSPACE = 'workspace';
    /** Console interne de l'équipe Joseara. */
    public const BRANCHE_CONSOLE = 'console';

    public const BRANCHES = [self::BRANCHE_PORTAIL, self::BRANCHE_WORKSPACE, self::BRANCHE_CONSOLE];

    /** Jamais regardée. */
    public const STATUT_NOUVELLE = 'nouvelle';
    /** Quelqu'un s'en occupe. */
    public const STATUT_EN_COURS = 'en_cours';
    /** Corrigée — si elle reparaît, le correctif n'a pas tenu. */
    public const STATUT_RESOLUE = 'resolue';
    /** Vue, comprise, assumée : bruit connu qu'on ne corrigera pas. */
    public const STATUT_IGNOREE = 'ignoree';

    public const STATUTS = [
        self::STATUT_NOUVELLE,
        self::STATUT_EN_COURS,
        self::STATUT_RESOLUE,
        self::STATUT_IGNOREE,
    ];

    /**
     * Seuils d'aggravation. Franchir l'un d'eux relance une alerte : une erreur
     * qui passe de 9 à 10 occurrences n'est plus le même problème que la
     * première fois, et l'apprendre trois jours plus tard coûte cher.
     */
    public const PALIERS = [10, 100, 1000];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    /** Empreinte de regroupement : sha256(cote + type + fichier + ligne). */
    #[ORM\Column(length: 64, unique: true)]
    private ?string $signature = null;

    #[ORM\Column(length: 16)]
    #[Groups(['list:read'])]
    private string $cote = self::COTE_SERVEUR;

    #[ORM\Column(length: 16)]
    #[Groups(['list:read'])]
    private string $branche = self::BRANCHE_WORKSPACE;

    /** Classe d'exception PHP, ou nom du type d'erreur JavaScript. */
    #[ORM\Column(length: 180)]
    #[Groups(['list:read'])]
    private ?string $type = null;

    /** Message de la DERNIÈRE occurrence : il varie d'une fois à l'autre. */
    #[ORM\Column(length: 500)]
    #[Groups(['list:read'])]
    private ?string $message = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $fichier = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?int $ligne = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $trace = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $navigateur = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $dernierUtilisateurEmail = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $dernierCabinet = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private int $nombreOccurrences = 0;

    #[ORM\Column]
    private ?\DateTimeImmutable $premiereOccurrenceAt = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $derniereOccurrenceAt = null;

    #[ORM\Column(length: 16)]
    #[Groups(['list:read'])]
    private string $statut = self::STATUT_NOUVELLE;

    /**
     * ON DELETE SET NULL : le départ d'un collaborateur ne doit pas effacer une
     * erreur, seulement la rendre à nouveau libre.
     */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $assigneA = null;

    /** Dernier palier d'aggravation déjà annoncé, pour ne pas le réannoncer. */
    #[ORM\Column]
    private int $dernierPalierAlerte = 0;

    /**
     * L'empreinte de regroupement.
     *
     * Le message en est volontairement absent : voir le commentaire de classe.
     * Le chemin du fichier est normalisé pour qu'un déménagement du code ne
     * transforme pas un défaut connu en un défaut neuf.
     */
    public static function signatureDe(string $cote, string $type, ?string $fichier, ?int $ligne): string
    {
        // Les antislashs d'abord : sans quoi « src/Service\X.php » (Windows) et
        // « src/Service/X.php » (serveur) deviendraient deux défauts distincts,
        // et le compteur repartirait de zéro à chaque déploiement.
        $normalise = $fichier === null ? '' : str_replace('\\', '/', $fichier);
        $normalise = (string) preg_replace('#^.*/(src|assets|public|templates|vendor)/#', '$1/', $normalise);

        return hash('sha256', $cote . '|' . $type . '|' . $normalise . '|' . ($ligne ?? 0));
    }

    /**
     * Enregistre une occurrence : incrémente le compteur et rafraîchit le
     * contexte, qui décrit toujours la DERNIÈRE fois — la plus utile à qui
     * cherche à reproduire.
     *
     * @return bool true si l'erreur était résolue et vient de RÉGRESSER
     */
    public function enregistrerOccurrence(\DateTimeImmutable $quand): bool
    {
        ++$this->nombreOccurrences;
        $this->derniereOccurrenceAt = $quand;
        $this->premiereOccurrenceAt ??= $quand;

        // Une erreur corrigée qui reparaît n'est pas une erreur ordinaire :
        // c'est un correctif qui n'a pas tenu. Elle redevient « nouvelle » pour
        // remonter en tête de liste, et le fait savoir.
        if (self::STATUT_RESOLUE === $this->statut) {
            $this->statut = self::STATUT_NOUVELLE;

            return true;
        }

        return false;
    }

    /**
     * Le palier d'aggravation que cette occurrence vient de franchir, ou null.
     * Chaque palier ne se franchit qu'une fois : au-delà, le silence reprend.
     */
    public function palierFranchi(): ?int
    {
        foreach (self::PALIERS as $palier) {
            if ($this->nombreOccurrences >= $palier && $this->dernierPalierAlerte < $palier) {
                $this->dernierPalierAlerte = $palier;

                return $palier;
            }
        }

        return null;
    }

    /** Vrai tant que personne n'a tranché : c'est ce qui reste à faire. */
    public function estOuverte(): bool
    {
        return in_array($this->statut, [self::STATUT_NOUVELLE, self::STATUT_EN_COURS], true);
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $maintenant = new \DateTimeImmutable();
        $this->premiereOccurrenceAt ??= $maintenant;
        $this->derniereOccurrenceAt ??= $maintenant;
    }

    public function getId(): ?int { return $this->id; }

    public function getSignature(): ?string { return $this->signature; }
    public function setSignature(string $signature): static { $this->signature = $signature; return $this; }

    public function getCote(): string { return $this->cote; }
    public function setCote(string $cote): static { $this->cote = $cote; return $this; }

    public function getBranche(): string { return $this->branche; }
    public function setBranche(string $branche): static { $this->branche = $branche; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(string $message): static { $this->message = $message; return $this; }

    public function getFichier(): ?string { return $this->fichier; }
    public function setFichier(?string $fichier): static { $this->fichier = $fichier; return $this; }

    public function getLigne(): ?int { return $this->ligne; }
    public function setLigne(?int $ligne): static { $this->ligne = $ligne; return $this; }

    public function getTrace(): ?string { return $this->trace; }
    public function setTrace(?string $trace): static { $this->trace = $trace; return $this; }

    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $url): static { $this->url = $url; return $this; }

    public function getNavigateur(): ?string { return $this->navigateur; }
    public function setNavigateur(?string $navigateur): static { $this->navigateur = $navigateur; return $this; }

    public function getDernierUtilisateurEmail(): ?string { return $this->dernierUtilisateurEmail; }
    public function setDernierUtilisateurEmail(?string $email): static { $this->dernierUtilisateurEmail = $email; return $this; }

    public function getDernierCabinet(): ?string { return $this->dernierCabinet; }
    public function setDernierCabinet(?string $cabinet): static { $this->dernierCabinet = $cabinet; return $this; }

    public function getNombreOccurrences(): int { return $this->nombreOccurrences; }
    public function setNombreOccurrences(int $nombre): static { $this->nombreOccurrences = $nombre; return $this; }

    public function getPremiereOccurrenceAt(): ?\DateTimeImmutable { return $this->premiereOccurrenceAt; }
    public function setPremiereOccurrenceAt(\DateTimeImmutable $quand): static { $this->premiereOccurrenceAt = $quand; return $this; }

    public function getDerniereOccurrenceAt(): ?\DateTimeImmutable { return $this->derniereOccurrenceAt; }
    public function setDerniereOccurrenceAt(\DateTimeImmutable $quand): static { $this->derniereOccurrenceAt = $quand; return $this; }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $statut): static { $this->statut = $statut; return $this; }

    public function getAssigneA(): ?Utilisateur { return $this->assigneA; }
    public function setAssigneA(?Utilisateur $assigneA): static { $this->assigneA = $assigneA; return $this; }

    public function getDernierPalierAlerte(): int { return $this->dernierPalierAlerte; }
    public function setDernierPalierAlerte(int $palier): static { $this->dernierPalierAlerte = $palier; return $this; }
}
