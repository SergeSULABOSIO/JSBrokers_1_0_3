<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\PeriodeBlocageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @file Période pendant laquelle aucun congé ne peut être posé (CTRL-05).
 * @description Clôture d'exercice, campagne de renouvellement : des moments où le cabinet
 * a besoin de tout le monde. La règle n'est pas « on n'en pose plus », c'est « on ne les
 * pose pas SANS EN PARLER » — un valideur peut passer outre, et le mail de soumission le
 * signale pour que personne ne découvre le contournement après coup.
 *
 * ── ELLE SE DÉSACTIVE, ELLE NE SE SUPPRIME PAS ──────────────────────────────────────
 * Une période passée explique des refus passés. La retirer de la base rendrait ces refus
 * incompréhensibles à qui relira l'historique l'an prochain.
 *
 * Vit dans le dialogue « Paramètres congés », en collection : ce n'est pas une rubrique à
 * elle seule, c'est un réglage parmi d'autres.
 */
#[ORM\Entity(repositoryClass: PeriodeBlocageRepository::class)]
#[ORM\HasLifecycleCallbacks]
class PeriodeBlocage implements OwnerAwareInterface
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'periodesBlocage')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ParametresConge $parametres = null;

    #[Assert\NotBlank(message: 'Le motif du blocage ne peut pas être vide.')]
    #[ORM\Column(length: 150)]
    #[Groups(['list:read'])]
    private ?string $libelle = null;

    #[Assert\NotNull(message: 'La date de début est obligatoire.')]
    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $dateDebut = null;

    #[Assert\NotNull(message: 'La date de fin est obligatoire.')]
    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private bool $actif = true;

    #[Groups(['list:read'])]
    public ?string $periodeLibelle = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParametres(): ?ParametresConge
    {
        return $this->parametres;
    }

    public function setParametres(?ParametresConge $parametres): static
    {
        $this->parametres = $parametres;

        return $this;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;

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

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    /**
     * La période bloque-t-elle l'intervalle donné ?
     *
     * Deux intervalles fermés se chevauchent dès que l'un commence avant que l'autre ne
     * finisse. Une demande n'a pas besoin d'être ENTIÈREMENT dans la période pour être
     * concernée : y entrer d'un seul jour suffit.
     */
    public function chevauche(?\DateTimeInterface $debut, ?\DateTimeInterface $fin): bool
    {
        if (!$this->actif || $this->dateDebut === null || $this->dateFin === null
            || $debut === null || $fin === null) {
            return false;
        }

        return $debut <= $this->dateFin && $fin >= $this->dateDebut;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s (du %s au %s)',
            $this->libelle ?? 'Période de blocage',
            $this->dateDebut?->format('d/m/Y') ?? '?',
            $this->dateFin?->format('d/m/Y') ?? '?',
        );
    }
}
