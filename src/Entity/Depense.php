<?php

namespace App\Entity;

use App\Repository\DepenseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @file Dépense réelle de JS Brokers (sortie de fonds), classée par type de charge.
 * @description Enregistre un décaissement (ou un engagement) de l'entreprise
 * JS Brokers, rattaché à une Charge (compte OHADA + axe analytique). Le montant est
 * saisi en TTC (devise par défaut USD). Le statut distingue l'engagement comptable
 * (charge du résultat) du paiement effectif (impact trésorerie) : seules les
 * dépenses « payées » décaissent la trésorerie ; toute dépense non « annulée »
 * pèse sur le résultat. Saisie et gérée par l'équipe JS Brokers depuis la Console.
 */
#[ORM\Entity(repositoryClass: DepenseRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Depense
{
    // --- Moyen de paiement / décaissement. ---
    public const MOYEN_CAISSE = 'caisse';
    public const MOYEN_BANQUE = 'banque';
    public const MOYEN_MOBILE_MONEY = 'mobile_money';
    public const MOYEN_CARTE = 'carte';
    public const MOYEN_VIREMENT = 'virement';

    /** @var array<string, string> */
    public const MOYENS_PAIEMENT = [
        self::MOYEN_CAISSE       => 'Caisse (espèces)',
        self::MOYEN_BANQUE       => 'Banque',
        self::MOYEN_MOBILE_MONEY => 'Mobile money',
        self::MOYEN_CARTE        => 'Carte',
        self::MOYEN_VIREMENT     => 'Virement',
    ];

    // --- Statut comptable. ---
    /** Charge engagée (constatée) mais pas encore décaissée. */
    public const STATUT_ENGAGEE = 'engagee';
    /** Charge engagée ET payée (décaisse la trésorerie). */
    public const STATUT_PAYEE = 'payee';
    /** Annulée : exclue du résultat et de la trésorerie. */
    public const STATUT_ANNULEE = 'annulee';

    /** @var array<string, string> */
    public const STATUTS = [
        self::STATUT_ENGAGEE => 'Engagée',
        self::STATUT_PAYEE   => 'Payée',
        self::STATUT_ANNULEE => 'Annulée',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[Assert\NotNull(message: 'La charge de rattachement est obligatoire.')]
    #[ORM\ManyToOne(targetEntity: Charge::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['list:read'])]
    private ?Charge $charge = null;

    #[Assert\NotNull(message: 'La date de la dépense est obligatoire.')]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $dateDepense = null;

    /** Montant TTC de la dépense, en devise. */
    #[Assert\NotBlank(message: 'Le montant ne peut pas être vide.')]
    #[Assert\Positive(message: 'Le montant doit être strictement positif.')]
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['list:read'])]
    private ?string $montant = null;

    #[ORM\Column(length: 3)]
    #[Groups(['list:read'])]
    private string $devise = 'USD';

    /** Bénéficiaire / fournisseur. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $beneficiaire = null;

    /** Référence de la pièce justificative (n° facture / reçu). */
    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $reference = null;

    #[Assert\Choice(callback: 'moyenPaiementKeys')]
    #[ORM\Column(length: 15)]
    #[Groups(['list:read'])]
    private string $moyenPaiement = self::MOYEN_BANQUE;

    #[Assert\Choice(callback: 'statutKeys')]
    #[ORM\Column(length: 15)]
    #[Groups(['list:read'])]
    private string $statut = self::STATUT_ENGAGEE;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    /** @return string[] Clés valides de moyen de paiement (callback de validation). */
    public static function moyenPaiementKeys(): array
    {
        return array_keys(self::MOYENS_PAIEMENT);
    }

    /** @return string[] Clés valides de statut (callback de validation). */
    public static function statutKeys(): array
    {
        return array_keys(self::STATUTS);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharge(): ?Charge
    {
        return $this->charge;
    }

    public function setCharge(?Charge $charge): static
    {
        $this->charge = $charge;

        return $this;
    }

    public function getDateDepense(): ?\DateTimeImmutable
    {
        return $this->dateDepense;
    }

    public function setDateDepense(?\DateTimeImmutable $dateDepense): static
    {
        $this->dateDepense = $dateDepense;

        return $this;
    }

    public function getMontant(): ?string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): static
    {
        $this->montant = $montant;

        return $this;
    }

    /** Montant exploitable pour le calcul (cast depuis le décimal stocké en chaîne). */
    public function getMontantFloat(): float
    {
        return (float) $this->montant;
    }

    public function getDevise(): string
    {
        return $this->devise;
    }

    public function setDevise(string $devise): static
    {
        $this->devise = strtoupper(trim($devise));

        return $this;
    }

    public function getBeneficiaire(): ?string
    {
        return $this->beneficiaire;
    }

    public function setBeneficiaire(?string $beneficiaire): static
    {
        $this->beneficiaire = $beneficiaire;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getMoyenPaiement(): string
    {
        return $this->moyenPaiement;
    }

    public function setMoyenPaiement(string $moyenPaiement): static
    {
        $this->moyenPaiement = $moyenPaiement;

        return $this;
    }

    public function getMoyenPaiementLabel(): string
    {
        return self::MOYENS_PAIEMENT[$this->moyenPaiement] ?? $this->moyenPaiement;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getStatutLabel(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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
}
