<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use Symfony\Component\Serializer\Annotation\Groups;
use App\Repository\ArticleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entité Article - Refactorisée pour Symfony 7.1.5
 * Suppression des champs 'nom' et 'taxeFacturee'.
 */
#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Article
{
    use AuditableTrait;
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    /**
     * Quantité décimale (ex: 1.5, 10.0)
     */
    #[ORM\Column(type: 'float', nullable: true)]
    #[Groups(['list:read'])]
    private ?float $quantite = null;

    #[ORM\ManyToOne(targetEntity: Tranche::class, inversedBy: 'articles')]
    #[Groups(['list:read'])]
    private ?Tranche $tranche = null; // Pas de sérialisation directe pour éviter les boucles

    #[ORM\ManyToOne(targetEntity: Note::class, inversedBy: 'articles')]
    private ?Note $note = null; // Pas de sérialisation directe pour éviter les boucles

    #[ORM\ManyToOne(targetEntity: RevenuPourCourtier::class, inversedBy: 'articles')]
    #[Groups(['list:read'])]
    private ?RevenuPourCourtier $revenuFacture = null; // Sérialisation directe pour l'ID et les propriétés simples

    // --- Attributs calculés (non persistés) ---
    // Hydratés par ArticleIndicatorStrategy et sérialisables.

    #[Groups(['list:read'])]
    public ?string $natureArticle = null;

    #[Groups(['list:read'])]
    public ?string $elementLie = null;

    #[Groups(['list:read'])]
    public ?float $montantArticle = null;

    #[Groups(['list:read'])]
    public ?float $pourcentageNote = null;

    #[Groups(['list:read'])]
    public ?string $statutNoteParent = null;

    #[Groups(['list:read'])]
    public ?string $description = null;

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getQuantite(): ?float
    {
        return $this->quantite;
    }

    public function setQuantite(?float $quantite): static
    {
        $this->quantite = $quantite;
        return $this;
    }

    public function getTranche(): ?Tranche
    {
        return $this->tranche;
    }

    public function setTranche(?Tranche $tranche): static
    {
        $this->tranche = $tranche;
        return $this;
    }

    public function getNote(): ?Note
    {
        return $this->note;
    }

    public function setNote(?Note $note): static
    {
        $this->note = $note;
        return $this;
    }

    public function getRevenuFacture(): ?RevenuPourCourtier
    {
        return $this->revenuFacture;
    }

    public function setRevenuFacture(?RevenuPourCourtier $revenuFacture): static
    {
        $this->revenuFacture = $revenuFacture;
        return $this;
    }
}