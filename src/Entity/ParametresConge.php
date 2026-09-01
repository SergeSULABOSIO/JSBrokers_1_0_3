<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\ParametresCongeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @file Réglages du module de congés, un jeu par cabinet.
 * @description Ce sont les valeurs qui gouvernent les contrôles de la soumission : délai
 * de préavis, nombre maximum d'absents simultanés, périodes de blocage, seuil d'alerte
 * sur le report.
 *
 * ── POURQUOI UNE ENTITÉ, ET NON DES CONSTANTES ──────────────────────────────────────
 * Un préavis de cinq jours convient à un cabinet et pas à l'autre. Coder ces valeurs en
 * dur aurait obligé chaque cabinet à demander un déploiement pour changer un chiffre qui
 * ne regarde que lui — et aurait rendu impossible de désactiver un contrôle dont il ne
 * veut pas.
 *
 * ── UN SEUL ENREGISTREMENT PAR CABINET ──────────────────────────────────────────────
 * La rubrique n'offre pas la création : ParametresCongeRepository::pourEntreprise() rend
 * celui du cabinet, ou en fabrique un aux valeurs par défaut. Deux jeux de réglages
 * concurrents, c'est un contrôle qui s'applique ou non selon la ligne qu'on a lue.
 *
 * ── CHAQUE CONTRÔLE SE DÉSACTIVE ────────────────────────────────────────────────────
 * Un plafond nul ou absent signifie « pas de plafond ». Un cabinet qui ne veut pas d'un
 * contrôle doit pouvoir l'éteindre, sans quoi il apprendra à le contourner.
 */
#[ORM\Entity(repositoryClass: ParametresCongeRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ParametresConge implements OwnerAwareInterface
{
    use AuditableTrait;

    /** Délai de préavis par défaut, en jours ouvrables (CTRL-03). */
    public const PREAVIS_DEFAUT = 5;

    /**
     * Seuil d'alerte sur le report, exprimé en MULTIPLE de la dotation annuelle.
     *
     * Le report étant sans limite de durée, un solde peut s'accumuler indéfiniment : c'est
     * une dette qui grossit sans que personne ne la regarde. Au-delà de ce multiple, le
     * propriétaire est averti.
     */
    public const SEUIL_REPORT_DEFAUT = '2.00';

    /** Relance des demandes en attente, en jours ouvrables. */
    public const RELANCE_DEFAUT = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    /**
     * Délai minimal entre la soumission et le premier jour d'absence, en jours ouvrables.
     * Zéro désactive le contrôle.
     */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private int $delaiPreavisJours = self::PREAVIS_DEFAUT;

    /**
     * Nombre maximum de collaborateurs d'une même équipe absents en même temps.
     * Null ou zéro désactive le contrôle.
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?int $maxAbsentsSimultanes = null;

    /** Multiple de la dotation annuelle au-delà duquel le report est signalé. */
    #[ORM\Column(type: 'decimal', precision: 4, scale: 2)]
    #[Groups(['list:read'])]
    private ?string $seuilAlerteReport = self::SEUIL_REPORT_DEFAUT;

    /** Une demande sans décision depuis ce nombre de jours ouvrables est relancée. */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private int $relanceApresJours = self::RELANCE_DEFAUT;

    /** Dotation annuelle appliquée aux nouveaux collaborateurs de ce cabinet. */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 1)]
    #[Groups(['list:read'])]
    private ?string $dotationAnnuelle = null;

    /**
     * Périodes pendant lesquelles aucun congé ne peut commencer (CTRL-05).
     *
     * @var Collection<int, PeriodeBlocage>
     */
    #[ORM\OneToMany(targetEntity: PeriodeBlocage::class, mappedBy: 'parametres', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $periodesBlocage;

    #[Groups(['list:read'])]
    public ?string $resumeReglages = null;

    #[Groups(['list:read'])]
    public ?int $nombrePeriodesBlocage = null;

    public function __construct()
    {
        $this->periodesBlocage = new ArrayCollection();
        $this->dotationAnnuelle = number_format(
            \App\Service\Conge\CongeParametres::DOTATION_ANNUELLE_DEFAUT,
            1,
            '.',
            '',
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDelaiPreavisJours(): int
    {
        return $this->delaiPreavisJours;
    }

    public function setDelaiPreavisJours(int $delaiPreavisJours): static
    {
        $this->delaiPreavisJours = max(0, $delaiPreavisJours);

        return $this;
    }

    public function getMaxAbsentsSimultanes(): ?int
    {
        return $this->maxAbsentsSimultanes;
    }

    public function setMaxAbsentsSimultanes(?int $maxAbsentsSimultanes): static
    {
        $this->maxAbsentsSimultanes = $maxAbsentsSimultanes;

        return $this;
    }

    public function getSeuilAlerteReport(): ?string
    {
        return $this->seuilAlerteReport;
    }

    public function setSeuilAlerteReport(?string $seuilAlerteReport): static
    {
        $this->seuilAlerteReport = $seuilAlerteReport;

        return $this;
    }

    public function getRelanceApresJours(): int
    {
        return $this->relanceApresJours;
    }

    public function setRelanceApresJours(int $relanceApresJours): static
    {
        $this->relanceApresJours = max(0, $relanceApresJours);

        return $this;
    }

    public function getDotationAnnuelle(): ?string
    {
        return $this->dotationAnnuelle;
    }

    public function setDotationAnnuelle(?string $dotationAnnuelle): static
    {
        $this->dotationAnnuelle = $dotationAnnuelle;

        return $this;
    }

    /**
     * @return Collection<int, PeriodeBlocage>
     */
    public function getPeriodesBlocage(): Collection
    {
        return $this->periodesBlocage;
    }

    public function addPeriodesBlocage(PeriodeBlocage $periode): static
    {
        if (!$this->periodesBlocage->contains($periode)) {
            $this->periodesBlocage->add($periode);
            $periode->setParametres($this);
        }

        return $this;
    }

    public function removePeriodesBlocage(PeriodeBlocage $periode): static
    {
        if ($this->periodesBlocage->removeElement($periode) && $periode->getParametres() === $this) {
            $periode->setParametres(null);
        }

        return $this;
    }

    // ── Lectures dérivées ────────────────────────────────────────────────────────────

    /** Le préavis est-il contrôlé ? Zéro = désactivé. */
    public function controlePreavis(): bool
    {
        return $this->delaiPreavisJours > 0;
    }

    /** Le plafond d'absents simultanés est-il contrôlé ? Null ou zéro = désactivé. */
    public function controleAbsentsSimultanes(): bool
    {
        return $this->maxAbsentsSimultanes !== null && $this->maxAbsentsSimultanes > 0;
    }

    public function dotationAnnuelleFloat(): float
    {
        return (float) ($this->dotationAnnuelle ?? \App\Service\Conge\CongeParametres::DOTATION_ANNUELLE_DEFAUT);
    }

    public function seuilAlerteReportFloat(): float
    {
        return (float) ($this->seuilAlerteReport ?? self::SEUIL_REPORT_DEFAUT);
    }

    public function __toString(): string
    {
        return 'Paramètres des congés';
    }
}
