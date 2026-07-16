<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\PaiementPrimeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Signalement du paiement d'une prime d'assurance sur une tranche.
 *
 * Marché par défaut : l'ASSUREUR facture et encaisse la prime — le courtier ne fait
 * que TRACER l'information (transmise par le client ou l'assureur) pour savoir quand
 * sa commission de courtage devient exigible. Ce signalement n'impacte JAMAIS la
 * trésorerie ni la comptabilité du courtier (aucun lien avec Note/Paiement/compte
 * bancaire) : c'est une preuve déclarative, éventuellement justifiée par des documents.
 */
#[ORM\Entity(repositoryClass: PaiementPrimeRepository::class)]
#[ORM\HasLifecycleCallbacks]
class PaiementPrime
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'paiementsPrime')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tranche $tranche = null;

    /** Date à laquelle l'assuré a réglé la prime (encaissée par l'assureur). */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $paidAt = null;

    /** Montant de prime réglé (paiements partiels possibles). */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?float $montant = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $reference = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    /**
     * @var Collection<int, Document> Pièces justificatives (avis de l'assureur, reçu…).
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'paiementPrime', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $preuves;

    public function __construct()
    {
        $this->preuves = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->reference ?? 'Paiement de prime', $this->paidAt?->format('d/m/Y') ?? 'sans date');
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getMontant(): ?float
    {
        return $this->montant;
    }

    public function setMontant(?float $montant): static
    {
        $this->montant = $montant;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
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

    /**
     * @return Collection<int, Document>
     */
    public function getPreuves(): Collection
    {
        return $this->preuves;
    }

    public function addPreuve(Document $preuve): static
    {
        if (!$this->preuves->contains($preuve)) {
            $this->preuves->add($preuve);
            $preuve->setPaiementPrime($this);
        }

        return $this;
    }

    public function removePreuve(Document $preuve): static
    {
        if ($this->preuves->removeElement($preuve)) {
            if ($preuve->getPaiementPrime() === $this) {
                $preuve->setPaiementPrime(null);
            }
        }

        return $this;
    }
}
