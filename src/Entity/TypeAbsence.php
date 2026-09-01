<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\TypeAbsenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @file Type d'absence du cabinet (module Administration → Congés).
 * @description Table de PARAMÉTRAGE, jamais une énumération figée dans le code : chaque
 * cabinet complète la sienne. Cinq types sont semés à la création de l'entreprise
 * (ServiceInitialisationEntreprise) et restent modifiables ensuite.
 *
 * `decompte` est le champ qui décide de tout : SEUL un type décompté produit un
 * mouvement sur le compteur de l'agent. Une maladie ou un événement familial sont
 * enregistrés pour le calendrier et l'historique, sans jamais toucher au solde.
 *
 * Un type déjà utilisé par une demande ou un mouvement se DÉSACTIVE (`actif = false`),
 * il ne se supprime pas : l'historique doit rester lisible.
 */
#[ORM\Entity(repositoryClass: TypeAbsenceRepository::class)]
#[ORM\HasLifecycleCallbacks]
class TypeAbsence implements OwnerAwareInterface
{
    use AuditableTrait;

    /** Codes des types semés d'office à la création du cabinet. */
    public const CODE_CONGE_ANNUEL = 'CA';
    public const CODE_SANS_SOLDE = 'SS';
    public const CODE_MALADIE = 'MAL';
    public const CODE_EVENEMENT_FAMILIAL = 'EVF';
    public const CODE_RECUPERATION = 'RECUP';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[Assert\NotBlank(message: "Le code du type d'absence ne peut pas être vide.")]
    #[ORM\Column(length: 20)]
    #[Groups(['list:read'])]
    private ?string $code = null;

    #[Assert\NotBlank(message: "Le libellé du type d'absence ne peut pas être vide.")]
    #[ORM\Column(length: 100)]
    #[Groups(['list:read'])]
    private ?string $libelle = null;

    /**
     * Le type se déduit-il du compteur de congés ? Seuls les types décomptés génèrent un
     * MouvementConge de nature PRISE à l'approbation (et ANNULATION au recrédit).
     */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private bool $decompte = true;

    /** Une pièce justificative est-elle exigée à la soumission ? */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private bool $justificatifRequis = false;

    /** Plafond de jours pour UNE demande de ce type. Null = aucun plafond. */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 1, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $plafondParDemande = null;

    /** Les demi-journées de bord sont-elles acceptées sur ce type ? */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private bool $autoriseDemiJournee = true;

    /** Couleur d'affichage au calendrier (code hexadécimal). */
    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $couleur = null;

    /** Type actif : proposé à la saisie d'une nouvelle demande. */
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

    public function setCode(string $code): static
    {
        $this->code = $code;

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

    public function isDecompte(): bool
    {
        return $this->decompte;
    }

    public function setDecompte(bool $decompte): static
    {
        $this->decompte = $decompte;

        return $this;
    }

    public function isJustificatifRequis(): bool
    {
        return $this->justificatifRequis;
    }

    public function setJustificatifRequis(bool $justificatifRequis): static
    {
        $this->justificatifRequis = $justificatifRequis;

        return $this;
    }

    public function getPlafondParDemande(): ?string
    {
        return $this->plafondParDemande;
    }

    public function setPlafondParDemande(?string $plafondParDemande): static
    {
        $this->plafondParDemande = $plafondParDemande;

        return $this;
    }

    public function isAutoriseDemiJournee(): bool
    {
        return $this->autoriseDemiJournee;
    }

    public function setAutoriseDemiJournee(bool $autoriseDemiJournee): static
    {
        $this->autoriseDemiJournee = $autoriseDemiJournee;

        return $this;
    }

    public function getCouleur(): ?string
    {
        return $this->couleur;
    }

    public function setCouleur(?string $couleur): static
    {
        $this->couleur = $couleur;

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

    public function __toString(): string
    {
        return $this->libelle ?? $this->code ?? "Type d'absence";
    }
}
