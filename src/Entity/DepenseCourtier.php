<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\DepenseCourtierRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @file Dépense réelle du COURTIER (sortie de fonds du cabinet), classée par charge.
 * @description Pendant workspace de Depense (console) : enregistre un décaissement
 * (ou un engagement) du cabinet de courtage, rattaché à une ChargeCourtier (compte
 * OHADA classe 6). Le montant est saisi en TTC, en monnaie fonctionnelle de
 * l'entreprise. Le statut distingue l'engagement comptable (charge du résultat)
 * du paiement effectif (impact trésorerie) : seules les dépenses « payées »
 * décaissent la trésorerie ; toute dépense non « annulée » pèse sur le résultat.
 * Alimente les documents comptables du courtier (journal, résultat, TFT…) et la
 * TVA déductible du suivi fiscal. Réutilise les référentiels de Depense
 * (moyens de paiement, statuts) — DRY.
 */
#[ORM\Entity(repositoryClass: DepenseCourtierRepository::class)]
#[ORM\HasLifecycleCallbacks]
class DepenseCourtier
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[Assert\NotNull(message: 'La charge de rattachement est obligatoire.')]
    #[ORM\ManyToOne(targetEntity: ChargeCourtier::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['list:read'])]
    private ?ChargeCourtier $charge = null;

    #[Assert\NotNull(message: 'La date de la dépense est obligatoire.')]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $dateDepense = null;

    /** Montant TTC de la dépense, en monnaie fonctionnelle. */
    #[Assert\NotBlank(message: 'Le montant ne peut pas être vide.')]
    #[Assert\Positive(message: 'Le montant doit être strictement positif.')]
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Groups(['list:read'])]
    private ?string $montant = null;

    /**
     * Taux de TVA déductible (récupérable) en pourcentage, appliqué au montant TTC.
     * 0 si la TVA n'est pas récupérable (montant entièrement comptabilisé en charge).
     */
    #[Assert\PositiveOrZero(message: 'Le taux de TVA ne peut pas être négatif.')]
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['list:read'])]
    private string $tauxTva = '0.00';

    /** Bénéficiaire / fournisseur. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $beneficiaire = null;

    /** Référence de la pièce justificative (n° facture / reçu). */
    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $reference = null;

    #[Assert\Choice(callback: [Depense::class, 'moyenPaiementKeys'])]
    #[ORM\Column(length: 15)]
    #[Groups(['list:read'])]
    private string $moyenPaiement = Depense::MOYEN_BANQUE;

    #[Assert\Choice(callback: [Depense::class, 'statutKeys'])]
    #[ORM\Column(length: 15)]
    #[Groups(['list:read'])]
    private string $statut = Depense::STATUT_ENGAGEE;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharge(): ?ChargeCourtier
    {
        return $this->charge;
    }

    public function setCharge(?ChargeCourtier $charge): static
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

    public function getTauxTva(): string
    {
        return $this->tauxTva;
    }

    public function setTauxTva(?string $tauxTva): static
    {
        // Repli sur 0 si vide : un champ laissé vide signifie « pas de TVA récupérable ».
        $this->tauxTva = ($tauxTva === null || $tauxTva === '') ? '0.00' : $tauxTva;

        return $this;
    }

    /** Taux de TVA déductible exploitable pour le calcul. */
    public function getTauxTvaFloat(): float
    {
        return (float) $this->tauxTva;
    }

    /**
     * Montant HORS TAXE (base de la charge au compte de résultat) : le TTC saisi
     * dégrevé de la TVA déductible. Égal au TTC quand le taux est nul.
     */
    public function getMontantHtFloat(): float
    {
        return $this->getMontantFloat() / (1 + $this->getTauxTvaFloat() / 100);
    }

    /** Part de TVA déductible (récupérable auprès de l'État) incluse dans le TTC. */
    public function getTvaDeductibleFloat(): float
    {
        return $this->getMontantFloat() - $this->getMontantHtFloat();
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
        return Depense::MOYENS_PAIEMENT[$this->moyenPaiement] ?? $this->moyenPaiement;
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
        return Depense::STATUTS[$this->statut] ?? $this->statut;
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

    public function __toString(): string
    {
        return trim(sprintf('%s — %s', $this->charge?->getLibelle() ?? 'Dépense', $this->beneficiaire ?? '')) ?: 'Dépense';
    }
}
