<?php

namespace App\Entity;

use App\Repository\ChargeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @file Type de charge supportée par JS Brokers (référentiel comptable OHADA).
 * @description Décrit une catégorie de charge récurrente ou ponctuelle de
 * l'entreprise JS Brokers, rattachée à un compte de la classe 6 du plan comptable
 * SYSCOHADA. Porte en plus un axe analytique (exploitation / coût direct /
 * acquisition) qui alimente les indicateurs SaaS (CAC, marge brute), une nature
 * de comportement (fixe / variable) et une périodicité prévisionnelle. Une charge
 * classe les dépenses réelles (cf. Depense). Créée et gérée par l'équipe JS Brokers
 * depuis la Console.
 */
#[ORM\Entity(repositoryClass: ChargeRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['code'], message: 'Ce code de charge est déjà utilisé.')]
class Charge
{
    // --- Comptes de la classe 6 (SYSCOHADA révisé OHADA). ---
    /** @var array<string, string> Compte OHADA classe 6 => libellé. */
    public const COMPTES_OHADA = [
        '60' => 'Achats et variations de stocks',
        '61' => 'Transports',
        '62' => 'Services extérieurs A',
        '63' => 'Services extérieurs B',
        '64' => 'Impôts et taxes',
        '65' => 'Autres charges',
        '66' => 'Charges de personnel',
        '67' => 'Frais financiers et charges assimilées',
        '68' => "Dotations aux amortissements",
        '69' => 'Dotations aux provisions',
    ];

    // --- Axe analytique (destination de gestion). ---
    /** Frais généraux d'exploitation (ni coût direct, ni acquisition). */
    public const DEST_EXPLOITATION = 'exploitation';
    /** Coût direct du service rendu (infrastructure/COGS) → marge brute. */
    public const DEST_COUT_DIRECT = 'cout_direct';
    /** Dépense commerciale & marketing → coût d'acquisition client (CAC). */
    public const DEST_ACQUISITION = 'acquisition';

    /** @var array<string, string> */
    public const DESTINATIONS = [
        self::DEST_EXPLOITATION => 'Exploitation (frais généraux)',
        self::DEST_COUT_DIRECT  => 'Coût direct (service rendu)',
        self::DEST_ACQUISITION  => 'Acquisition (commercial & marketing)',
    ];

    // --- Comportement de la charge (analyse de marge / point mort). ---
    public const COMPORTEMENT_FIXE = 'fixe';
    public const COMPORTEMENT_VARIABLE = 'variable';

    /** @var array<string, string> */
    public const COMPORTEMENTS = [
        self::COMPORTEMENT_FIXE     => 'Fixe',
        self::COMPORTEMENT_VARIABLE => 'Variable',
    ];

    // --- Périodicité prévisionnelle. ---
    public const PERIODICITE_MENSUELLE = 'mensuelle';
    public const PERIODICITE_TRIMESTRIELLE = 'trimestrielle';
    public const PERIODICITE_ANNUELLE = 'annuelle';
    public const PERIODICITE_PONCTUELLE = 'ponctuelle';

    /** @var array<string, string> */
    public const PERIODICITES = [
        self::PERIODICITE_MENSUELLE     => 'Mensuelle',
        self::PERIODICITE_TRIMESTRIELLE => 'Trimestrielle',
        self::PERIODICITE_ANNUELLE      => 'Annuelle',
        self::PERIODICITE_PONCTUELLE    => 'Ponctuelle',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[Assert\NotBlank(message: 'Le code ne peut pas être vide.')]
    #[ORM\Column(length: 40, unique: true)]
    #[Groups(['list:read'])]
    private ?string $code = null;

    #[Assert\NotBlank(message: 'Le libellé ne peut pas être vide.')]
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $libelle = null;

    /** Compte OHADA classe 6 de rattachement (clé de self::COMPTES_OHADA). */
    #[Assert\Choice(choices: ['60', '61', '62', '63', '64', '65', '66', '67', '68', '69'])]
    #[ORM\Column(length: 10)]
    #[Groups(['list:read'])]
    private string $compteOhada = '65';

    #[Assert\Choice(callback: 'destinationKeys')]
    #[ORM\Column(length: 20)]
    #[Groups(['list:read'])]
    private string $destination = self::DEST_EXPLOITATION;

    #[Assert\Choice(callback: 'comportementKeys')]
    #[ORM\Column(length: 10)]
    #[Groups(['list:read'])]
    private string $comportement = self::COMPORTEMENT_FIXE;

    #[Assert\Choice(callback: 'periodiciteKeys')]
    #[ORM\Column(length: 15)]
    #[Groups(['list:read'])]
    private string $periodicite = self::PERIODICITE_MENSUELLE;

    /** Montant prévisionnel mensuel (budget), en USD. Null = non budgété. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $montantBudgeteMensuel = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private bool $actif = true;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    /** @return string[] Clés valides de destination (callback de validation). */
    public static function destinationKeys(): array
    {
        return array_keys(self::DESTINATIONS);
    }

    /** @return string[] Clés valides de comportement (callback de validation). */
    public static function comportementKeys(): array
    {
        return array_keys(self::COMPORTEMENTS);
    }

    /** @return string[] Clés valides de périodicité (callback de validation). */
    public static function periodiciteKeys(): array
    {
        return array_keys(self::PERIODICITES);
    }

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
        // Normalisation : code en majuscules, sans espaces superflus (cf. Coupon).
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
        return self::COMPTES_OHADA[$this->compteOhada] ?? $this->compteOhada;
    }

    public function getDestination(): string
    {
        return $this->destination;
    }

    public function setDestination(string $destination): static
    {
        $this->destination = $destination;

        return $this;
    }

    public function getDestinationLabel(): string
    {
        return self::DESTINATIONS[$this->destination] ?? $this->destination;
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
        return self::COMPORTEMENTS[$this->comportement] ?? $this->comportement;
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
        return self::PERIODICITES[$this->periodicite] ?? $this->periodicite;
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }

    public function __toString(): string
    {
        return trim(sprintf('%s — %s', $this->code ?? '', $this->libelle ?? '')) ?: 'Charge';
    }
}
