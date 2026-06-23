<?php

namespace App\Entity;

use App\Repository\TaxeVenteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @file Taxe propre à JS Brokers, due sur ses ventes (achats de paquets de tokens).
 * @description Distincte de l'entité Taxe (domaine assurance, tauxIARD/tauxVIE,
 * redevable courtier/assureur). Encapsule une taxe que JS Brokers reverse à une
 * autorité fiscale : nom + abréviation de l'autorité, taux en % appliqué sur la
 * vente totale. Plusieurs taxes peuvent coexister ; combinées en additif sur base
 * commune pour dégager le revenu hors taxe (montant / (1 + Σtaux/100)).
 * Créée et gérée par l'équipe JS Brokers depuis la Console.
 */
#[ORM\Entity(repositoryClass: TaxeVenteRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['code'], message: 'Ce code de taxe est déjà utilisé.')]
class TaxeVente
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[Assert\NotBlank(message: 'Le code ne peut pas être vide.')]
    #[ORM\Column(length: 50, unique: true)]
    #[Groups(['list:read'])]
    private ?string $code = null;

    #[Assert\NotBlank(message: 'Le libellé ne peut pas être vide.')]
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $libelle = null;

    #[Assert\NotBlank(message: "Le nom de l'autorité fiscale ne peut pas être vide.")]
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $autoriteNom = null;

    #[Assert\NotBlank(message: "L'abréviation de l'autorité fiscale ne peut pas être vide.")]
    #[ORM\Column(length: 20)]
    #[Groups(['list:read'])]
    private ?string $autoriteAbreviation = null;

    /** Taux en pourcentage appliqué sur la vente totale (ex. 16.00). */
    #[Assert\NotBlank(message: 'Le taux ne peut pas être vide.')]
    #[Assert\Positive(message: 'Le taux doit être strictement positif.')]
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Groups(['list:read'])]
    private ?string $taux = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private bool $actif = true;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $createdAt = null;

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

    public function getAutoriteNom(): ?string
    {
        return $this->autoriteNom;
    }

    public function setAutoriteNom(string $autoriteNom): static
    {
        $this->autoriteNom = $autoriteNom;

        return $this;
    }

    public function getAutoriteAbreviation(): ?string
    {
        return $this->autoriteAbreviation;
    }

    public function setAutoriteAbreviation(string $autoriteAbreviation): static
    {
        $this->autoriteAbreviation = $autoriteAbreviation;

        return $this;
    }

    public function getTaux(): ?string
    {
        return $this->taux;
    }

    public function setTaux(string $taux): static
    {
        $this->taux = $taux;

        return $this;
    }

    /** Taux exploitable pour le calcul (cast depuis le décimal stocké en chaîne). */
    public function getTauxFloat(): float
    {
        return (float) $this->taux;
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
        return $this->code ?? 'Taxe sans code';
    }
}
