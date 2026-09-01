<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\RegimeTravailRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @file Régime de travail d'un collaborateur (module Administration → Congés).
 * @description Quels jours de la semaine l'agent travaille, et à quel taux. C'est ce
 * qui permet à un temps partiel de ne pas se voir décompter ses jours non travaillés.
 *
 * HISTORISÉ : un changement de régime CRÉE une nouvelle ligne, il n'écrase pas
 * l'ancienne. Une demande posée l'an dernier doit rester lisible avec le régime qui
 * était alors le sien.
 *
 * AUCUNE LIGNE N'EST SEMÉE à la création du cabinet. Un agent sans régime est un temps
 * plein du lundi au vendredi : ce défaut vit dans CalculateurJoursOuvrables, source
 * unique, plutôt que recopié en base pour chaque collaborateur.
 *
 * Se saisit depuis la collection du dialogue Invité, pas depuis une rubrique : régler le
 * temps de travail de quelqu'un relève du même cercle que gérer les invités.
 */
#[ORM\Entity(repositoryClass: RegimeTravailRepository::class)]
#[ORM\HasLifecycleCallbacks]
class RegimeTravail implements OwnerAwareInterface
{
    use AuditableTrait;

    /**
     * Jours ouvrés par défaut, au format ISO-8601 (1 = lundi … 7 = dimanche). Repris par
     * CalculateurJoursOuvrables quand l'agent n'a aucun régime déclaré.
     *
     * @var int[]
     */
    public const JOURS_OUVRES_DEFAUT = [1, 2, 3, 4, 5];

    /** Libellés des jours, indexés par leur numéro ISO. */
    public const JOURS_LABELS = [
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
        7 => 'Dimanche',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    /** Le collaborateur concerné. « Agent » au sens de la spécification. */
    #[ORM\ManyToOne(inversedBy: 'regimesTravail')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Invite $agent = null;

    /**
     * Jours travaillés, en numéros ISO-8601.
     *
     * @var int[]
     */
    #[ORM\Column(type: Types::JSON)]
    private array $joursOuvres = self::JOURS_OUVRES_DEFAUT;

    /** Taux d'occupation : 1.00 pour un temps plein. */
    #[Assert\NotNull(message: "Le taux d'occupation est obligatoire.")]
    #[ORM\Column(type: 'decimal', precision: 4, scale: 2)]
    #[Groups(['list:read'])]
    private ?string $tauxOccupation = '1.00';

    #[Assert\NotNull(message: 'La date de début du régime est obligatoire.')]
    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $dateDebut = null;

    /** Null = régime encore en vigueur. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $dateFin = null;

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

    /** @return int[] */
    public function getJoursOuvres(): array
    {
        return $this->joursOuvres;
    }

    /** @param int[] $joursOuvres */
    public function setJoursOuvres(array $joursOuvres): static
    {
        $jours = array_values(array_unique(array_map('intval', $joursOuvres)));
        sort($jours);
        $this->joursOuvres = $jours;

        return $this;
    }

    public function getTauxOccupation(): ?string
    {
        return $this->tauxOccupation;
    }

    public function setTauxOccupation(?string $tauxOccupation): static
    {
        $this->tauxOccupation = $tauxOccupation;

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

    /** Le régime couvre-t-il la date donnée ? Borne de fin incluse, absence de fin = ouvert. */
    public function couvre(\DateTimeInterface $date): bool
    {
        if ($this->dateDebut !== null && $date < $this->dateDebut) {
            return false;
        }

        return $this->dateFin === null || $date <= $this->dateFin;
    }

    /** Libellé lisible des jours travaillés — « Lundi, Mardi, … ». */
    public function getJoursOuvresLibelle(): string
    {
        $labels = [];
        foreach ($this->joursOuvres as $jour) {
            $labels[] = self::JOURS_LABELS[$jour] ?? (string) $jour;
        }

        return implode(', ', $labels);
    }

    public function __toString(): string
    {
        return sprintf(
            'Régime %s (%s)',
            $this->dateDebut?->format('d/m/Y') ?? '',
            $this->getJoursOuvresLibelle(),
        );
    }
}
