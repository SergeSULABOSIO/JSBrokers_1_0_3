<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\ReversementRetroAgentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Reversement d'une rétrocommission à un AGENT INTERNE du cabinet, sur UNE affaire.
 *
 * L'agent a apporté l'affaire (ou saturé une ligne neuve) ; une ConditionPartage à son
 * nom, rattachée à la piste, lui promet un pourcentage de ce qui reste au cabinet une
 * fois les taxes et les rétrocommissions des partenaires externes retirées. Cette entité
 * trace ce que le cabinet lui a effectivement VERSÉ, avenant par avenant.
 *
 * ── CE QUE CE N'EST PAS ─────────────────────────────────────────────────────────────
 * Ce n'est PAS une note de débit ni de crédit, et ce circuit ne passe donc ni par Note,
 * ni par Article, ni par Paiement — contrairement à la rétrocommission d'un partenaire
 * externe, qui se facture. La rémunération d'un salarié ne se facture pas : elle se
 * verse. Le « payé » et le « solde » d'une rétro agent se lisent donc ici, en clair,
 * sans le prorata de note qu'exige l'autre circuit.
 *
 * ── LE LOT ──────────────────────────────────────────────────────────────────────────
 * Un virement unique couvrant dix affaires produit DIX lignes partageant le même
 * `lotReference`. Aucune entité d'en-tête n'a été créée pour cela : elle ne porterait
 * rien que la clé ne porte déjà. En échange, le solde reste exact affaire par affaire —
 * ce dont le rapport de production a besoin — sans aucun algorithme d'imputation, et la
 * comptabilité regroupe les lignes d'un même lot en UNE écriture, pour que le journal se
 * rapproche du relevé bancaire ligne à ligne.
 *
 * ── COMPTABILITÉ ────────────────────────────────────────────────────────────────────
 * Chaque reversement génère, à la volée, l'écriture SYSCOHADA D 6611 « Appointements,
 * salaires et commissions » / C 521 Banques (ou 571 Caisse) — cf.
 * CourtierEcritureComptableService. Rien n'est persisté : la comptabilité de ce projet
 * est dérivée de ses données transactionnelles.
 */
#[ORM\Entity(repositoryClass: ReversementRetroAgentRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ReversementRetroAgent
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    /** Le bénéficiaire — un invité de l'entreprise, porteur d'une condition de partage. */
    #[ORM\ManyToOne(inversedBy: 'reversementsRetroAgent')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['list:read'])]
    private ?Invite $agent = null;

    /** La LIGNE d'affaire soldée : c'est elle qui porte le montant dû. */
    #[ORM\ManyToOne(inversedBy: 'reversementsRetroAgent')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['list:read'])]
    private ?Avenant $avenant = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?float $montant = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $reference = null;

    /**
     * Clé de LOT : les lignes d'un même versement la partagent. Nulle pour un reversement
     * isolé — qui ne doit jamais se retrouver fondu dans le lot d'un autre.
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $lotReference = null;

    /**
     * Compte bancaire débité. Nul = espèces : même convention que Paiement, dont le
     * service comptable dérive déjà le compte de trésorerie (521 si renseigné, sinon 571).
     */
    #[ORM\ManyToOne]
    #[Groups(['list:read'])]
    private ?CompteBancaire $compteBancaire = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    /**
     * @var Collection<int, Document> Preuves du versement (bordereau de virement, reçu…).
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'reversementRetroAgent', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf(
            '%s (%s)',
            $this->reference ?? 'Rétro agent',
            $this->paidAt?->format('d/m/Y') ?? 'sans date',
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAgent(): ?Invite
    {
        return $this->agent;
    }

    public function setAgent(?Invite $agent): static
    {
        $this->agent = $agent;

        return $this;
    }

    public function getAvenant(): ?Avenant
    {
        return $this->avenant;
    }

    public function setAvenant(?Avenant $avenant): static
    {
        $this->avenant = $avenant;

        return $this;
    }

    public function getMontant(): ?float
    {
        return $this->montant;
    }

    public function setMontant(float $montant): static
    {
        $this->montant = $montant;

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

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getLotReference(): ?string
    {
        return $this->lotReference;
    }

    public function setLotReference(?string $lotReference): static
    {
        // La chaîne vide n'est pas un lot : elle grouperait ensemble tous les
        // reversements isolés d'un formulaire mal rempli.
        $this->lotReference = ($lotReference === '' ? null : $lotReference);

        return $this;
    }

    /**
     * Clé de regroupement comptable : le lot s'il existe, sinon l'identité de la ligne.
     * Source unique du « un versement réel = une écriture » (cf. le service comptable).
     */
    public function cleDeRegroupement(): string
    {
        return $this->lotReference ?? ('#' . ($this->id ?? spl_object_id($this)));
    }

    public function getCompteBancaire(): ?CompteBancaire
    {
        return $this->compteBancaire;
    }

    public function setCompteBancaire(?CompteBancaire $compteBancaire): static
    {
        $this->compteBancaire = $compteBancaire;

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
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setReversementRetroAgent($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document) && $document->getReversementRetroAgent() === $this) {
            $document->setReversementRetroAgent(null);
        }

        return $this;
    }
}
