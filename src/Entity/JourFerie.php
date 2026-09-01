<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\JourFerieRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @file Jour férié du cabinet (module Administration → Congés).
 * @description Un jour férié tombant dans une demande n'est pas décompté du solde.
 *
 * AUCUN CATALOGUE N'EST SEMÉ. Les jours fériés dépendent du pays du cabinet et, pour
 * les dates mobiles, de l'année : un catalogue en dur serait faux pour la moitié des
 * cabinets et périmé chaque janvier. Le valideur les saisit, et un calendrier vide ne
 * casse rien — le calcul ne retire alors que les week-ends et le régime de travail.
 *
 * Le résultat est de toute façon FIGÉ dans DemandeConge.nbJours à la soumission : une
 * correction ultérieure du calendrier ne réécrit jamais l'historique.
 */
#[ORM\Entity(repositoryClass: JourFerieRepository::class)]
#[ORM\HasLifecycleCallbacks]
class JourFerie implements OwnerAwareInterface
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[Assert\NotNull(message: 'La date du jour férié est obligatoire.')]
    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $date = null;

    #[Assert\NotBlank(message: 'Le libellé du jour férié ne peut pas être vide.')]
    #[ORM\Column(length: 150)]
    #[Groups(['list:read'])]
    private ?string $libelle = null;

    /**
     * Année civile de rattachement. Redondante avec `date` mais persistée : elle porte
     * le filtre par exercice sans fonction SQL sur une colonne indexée.
     */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $exercice = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): static
    {
        $this->date = $date;
        // L'exercice DÉCOULE de la date : on ne laisse pas les deux diverger.
        if ($date !== null) {
            $this->exercice = (int) $date->format('Y');
        }

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

    public function getExercice(): ?int
    {
        return $this->exercice;
    }

    public function setExercice(?int $exercice): static
    {
        $this->exercice = $exercice;

        return $this;
    }

    public function __toString(): string
    {
        return $this->libelle ?? 'Jour férié';
    }
}
