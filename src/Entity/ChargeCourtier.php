<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\ChargeCourtierRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @file Type de charge du COURTIER (référentiel comptable OHADA du workspace).
 * @description Pendant workspace de Charge (console) : décrit une catégorie de
 * charge du cabinet de courtage, rattachée à un compte de la classe 6 du plan
 * comptable SYSCOHADA (référentiel partagé Charge::COMPTES_OHADA — DRY). Une
 * charge classe les dépenses réelles du courtier (cf. DepenseCourtier) qui
 * alimentent ses documents comptables. Scopée à l'entreprise (AuditableTrait) :
 * chaque cabinet gère son propre référentiel. L'axe analytique SaaS de la
 * console (destination) n'a pas d'équivalent métier ici et n'est pas repris.
 */
#[ORM\Entity(repositoryClass: ChargeCourtierRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['entreprise', 'code'], message: 'Ce code de charge est déjà utilisé dans cet espace de travail.')]
class ChargeCourtier
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[Assert\NotBlank(message: 'Le code ne peut pas être vide.')]
    #[ORM\Column(length: 40)]
    #[Groups(['list:read'])]
    private ?string $code = null;

    #[Assert\NotBlank(message: 'Le libellé ne peut pas être vide.')]
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $libelle = null;

    /** Compte OHADA classe 6 de rattachement (clé de Charge::COMPTES_OHADA). */
    #[Assert\Choice(choices: ['60', '61', '62', '63', '64', '65', '66', '67', '68', '69'])]
    #[ORM\Column(length: 10)]
    #[Groups(['list:read'])]
    private string $compteOhada = '65';

    #[Assert\Choice(callback: [Charge::class, 'comportementKeys'])]
    #[ORM\Column(length: 10)]
    #[Groups(['list:read'])]
    private string $comportement = Charge::COMPORTEMENT_FIXE;

    #[Assert\Choice(callback: [Charge::class, 'periodiciteKeys'])]
    #[ORM\Column(length: 15)]
    #[Groups(['list:read'])]
    private string $periodicite = Charge::PERIODICITE_MENSUELLE;

    /** Montant prévisionnel mensuel (budget), en monnaie fonctionnelle. Null = non budgété. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $montantBudgeteMensuel = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private bool $actif = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        // Normalisation : code en majuscules, sans espaces superflus (cf. Charge console).
        $this->code = $code === null ? null : strtoupper(trim($code));

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

    public function getCompteOhada(): string
    {
        return $this->compteOhada;
    }

    public function setCompteOhada(string $compteOhada): static
    {
        $this->compteOhada = $compteOhada;

        return $this;
    }

    /** Libellé OHADA du compte de rattachement (repli sur le code). */
    public function getCompteOhadaLabel(): string
    {
        return Charge::COMPTES_OHADA[$this->compteOhada] ?? $this->compteOhada;
    }

    public function getComportement(): string
    {
        return $this->comportement;
    }

    public function setComportement(string $comportement): static
    {
        $this->comportement = $comportement;

        return $this;
    }

    public function getComportementLabel(): string
    {
        return Charge::COMPORTEMENTS[$this->comportement] ?? $this->comportement;
    }

    public function getPeriodicite(): string
    {
        return $this->periodicite;
    }

    public function setPeriodicite(string $periodicite): static
    {
        $this->periodicite = $periodicite;

        return $this;
    }

    public function getPeriodiciteLabel(): string
    {
        return Charge::PERIODICITES[$this->periodicite] ?? $this->periodicite;
    }

    public function getMontantBudgeteMensuel(): ?string
    {
        return $this->montantBudgeteMensuel;
    }

    public function setMontantBudgeteMensuel(?string $montantBudgeteMensuel): static
    {
        $this->montantBudgeteMensuel = $montantBudgeteMensuel;

        return $this;
    }

    /** Budget mensuel exploitable pour le calcul (0.0 si non renseigné). */
    public function getMontantBudgeteMensuelFloat(): float
    {
        return (float) $this->montantBudgeteMensuel;
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

    public function __toString(): string
    {
        return trim(sprintf('%s — %s', $this->code ?? '', $this->libelle ?? '')) ?: 'Charge';
    }
}
