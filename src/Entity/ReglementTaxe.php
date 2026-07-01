<?php

namespace App\Entity;

use App\Repository\ReglementTaxeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * @file Reversement de TVA à l'autorité fiscale (déclaration mensuelle).
 * @description Trace un paiement effectif de TVA nette dû par JS Brokers à une
 * autorité fiscale, pour une période (mois/année). Le « montant dû » est calculé
 * (TVA collectée − déductible, cf. SuiviFiscalService) ; cette entité enregistre
 * ce qui a été PAYÉ, afin d'en déduire le solde restant dû. Génère une écriture
 * comptable (D 443 État, TVA facturée / C trésorerie) via EcritureComptableService.
 */
#[ORM\Entity(repositoryClass: ReglementTaxeRepository::class)]
#[ORM\Table(name: 'reglement_taxe')]
#[ORM\HasLifecycleCallbacks]
class ReglementTaxe
{
    public const MOYEN_BANQUE = 'banque';
    public const MOYEN_CAISSE = 'caisse';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Autorité fiscale bénéficiaire (ex. « DGI »). */
    #[ORM\Column(length: 120)]
    private ?string $autorite = null;

    /** Année civile de la période déclarée. */
    #[ORM\Column]
    private int $annee = 0;

    /** Mois de la période déclarée (1 à 12). */
    #[ORM\Column]
    private int $mois = 1;

    /** Montant reversé (TVA nette payée), en USD. */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private ?string $montant = null;

    /**
     * Photo, à la saisie, de la TVA COLLECTÉE de la période restant à déclarer
     * (nette des reversements antérieurs de la même période). Fige l'écriture
     * comptable détaillée (D 443) indépendamment des ventes ajoutées plus tard.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, options: ['default' => '0'])]
    private string $tvaCollectee = '0';

    /** Idem pour la TVA DÉDUCTIBLE de la période restant à déclarer (C 445). */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, options: ['default' => '0'])]
    private string $tvaDeductible = '0';

    /** Date effective du paiement. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $datePaiement = null;

    /** Trésorerie débitée : banque ou caisse (pour l'écriture comptable). */
    #[ORM\Column(length: 20)]
    private string $moyenPaiement = self::MOYEN_BANQUE;

    /** Référence du paiement / de la déclaration (facultatif). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAutorite(): ?string
    {
        return $this->autorite;
    }

    public function setAutorite(string $autorite): static
    {
        $this->autorite = $autorite;

        return $this;
    }

    public function getAnnee(): int
    {
        return $this->annee;
    }

    public function setAnnee(int $annee): static
    {
        $this->annee = $annee;

        return $this;
    }

    public function getMois(): int
    {
        return $this->mois;
    }

    public function setMois(int $mois): static
    {
        $this->mois = $mois;

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

    /** Montant reversé exploitable pour le calcul (0 si non renseigné). */
    public function getMontantFloat(): float
    {
        return (float) $this->montant;
    }

    public function getTvaCollectee(): string
    {
        return $this->tvaCollectee;
    }

    public function setTvaCollectee(string $tvaCollectee): static
    {
        $this->tvaCollectee = $tvaCollectee;

        return $this;
    }

    public function getTvaCollecteeFloat(): float
    {
        return (float) $this->tvaCollectee;
    }

    public function getTvaDeductible(): string
    {
        return $this->tvaDeductible;
    }

    public function setTvaDeductible(string $tvaDeductible): static
    {
        $this->tvaDeductible = $tvaDeductible;

        return $this;
    }

    public function getTvaDeductibleFloat(): float
    {
        return (float) $this->tvaDeductible;
    }

    public function getDatePaiement(): ?\DateTimeImmutable
    {
        return $this->datePaiement;
    }

    public function setDatePaiement(?\DateTimeImmutable $datePaiement): static
    {
        $this->datePaiement = $datePaiement;

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

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

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

    /** Libellé court de la période : « MM/AAAA ». */
    public function getPeriodeLabel(): string
    {
        return sprintf('%02d/%d', $this->mois, $this->annee);
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }
}
