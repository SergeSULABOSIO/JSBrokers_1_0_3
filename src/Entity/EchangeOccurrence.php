<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\EchangeOccurrenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * UNE OPÉRATION D'ÉCHANGE ABOUTIE — un export effectivement produit, ou un import
 * effectivement committé. Jamais une tentative : un contrôle à blanc, un import annulé
 * ou une erreur ne laissent AUCUNE ligne ici, et ne débitent donc rien.
 *
 * Deux rôles, qui justifient qu'elle soit persistée plutôt que déduite :
 *
 *  1. LE COMPTEUR DE FACTURATION. Les premières occurrences d'un cabinet sont gratuites
 *     (quota lu dans la grille tarifaire), les suivantes sont facturées. Ce décompte est
 *     « à vie par cabinet » : il ne se reconstitue d'aucun journal de tokens, puisque
 *     les occurrences gratuites n'en consomment aucun.
 *
 *  2. LA TRAÇABILITÉ. Un export de cabinet fait SORTIR des données personnelles de
 *     prospects et d'assurés. En courtage, savoir qui a extrait quoi, quand et en quel
 *     volume n'est pas une commodité. C'est pourquoi l'historique n'est pas purgeable
 *     depuis l'interface, et pourquoi les imports y figurent aussi — même gratuits.
 *
 * L'entreprise vient d'AuditableTrait : c'est elle qui scope le décompte au cabinet.
 */
#[ORM\Entity(repositoryClass: EchangeOccurrenceRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_echange_occurrence_entreprise', columns: ['entreprise_id'])]
class EchangeOccurrence implements OwnerAwareInterface
{
    use AuditableTrait;

    public const TYPE_EXPORT = 'EXPORT';
    public const TYPE_IMPORT = 'IMPORT';

    public const TYPES = [
        self::TYPE_EXPORT => 'Exportation',
        self::TYPE_IMPORT => 'Importation',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    #[Groups(['list:read'])]
    private ?string $type = null;

    /**
     * Codes des ressources réellement présentes dans le fichier — ce qui est SORTI,
     * pas ce qui avait été demandé. Un périmètre restreint par les droits doit se lire
     * ici tel qu'il a été appliqué.
     *
     * @var string[]
     */
    #[ORM\Column(type: Types::JSON)]
    #[Groups(['list:read'])]
    private array $perimetre = [];

    #[ORM\Column]
    #[Groups(['list:read'])]
    private int $nbLignes = 0;

    /**
     * Tokens réellement débités. Zéro pour une occurrence gratuite ET pour un import :
     * l'import ne porte pas de forfait, il paie le métrage d'écriture ordinaire, ligne
     * par ligne, comme n'importe quelle saisie. Le distinguer ici éviterait de croire
     * qu'un import n'a rien coûté.
     */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private int $tokensDebites = 0;

    /**
     * Clé d'idempotence : interdit qu'un rejeu (double clic, requête relancée par le
     * navigateur, retry réseau) produise deux occurrences et deux débits. L'unicité est
     * portée par la BASE, pas par une vérification applicative — seule la contrainte
     * résiste à deux requêtes concurrentes.
     */
    #[ORM\Column(length: 64, unique: true)]
    private ?string $cleIdempotence = null;

    /** Empreinte du fichier produit ou déposé : relie l'occurrence à un contenu précis. */
    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $empreinteFichier = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $nomFichier = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getTypeLibelle(): string
    {
        return self::TYPES[$this->type] ?? (string) $this->type;
    }

    /** @return string[] */
    public function getPerimetre(): array
    {
        return $this->perimetre;
    }

    /** @param string[] $perimetre */
    public function setPerimetre(array $perimetre): static
    {
        $this->perimetre = array_values($perimetre);

        return $this;
    }

    public function getNbLignes(): int
    {
        return $this->nbLignes;
    }

    public function setNbLignes(int $nbLignes): static
    {
        $this->nbLignes = $nbLignes;

        return $this;
    }

    public function getTokensDebites(): int
    {
        return $this->tokensDebites;
    }

    public function setTokensDebites(int $tokensDebites): static
    {
        $this->tokensDebites = $tokensDebites;

        return $this;
    }

    public function getCleIdempotence(): ?string
    {
        return $this->cleIdempotence;
    }

    public function setCleIdempotence(string $cleIdempotence): static
    {
        $this->cleIdempotence = $cleIdempotence;

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

    public function getNomFichier(): ?string
    {
        return $this->nomFichier;
    }

    public function setNomFichier(?string $nomFichier): static
    {
        $this->nomFichier = $nomFichier;

        return $this;
    }
}
