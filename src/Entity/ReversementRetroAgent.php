<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\ReversementRetroAgentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Reversement d'une rétrocommission à un INTERMÉDIAIRE, échéance par échéance.
 *
 * ── UN SEUL CIRCUIT POUR LES DEUX FAMILLES ──────────────────────────────────────────
 * Le bénéficiaire est un AGENT interne OU un PARTENAIRE externe — jamais les deux, jamais
 * aucun. Chacun tient sa promesse d'une `ConditionPartage` à son nom : l'agent touche un
 * pourcentage de ce qui RESTE au cabinet une fois les partenaires servis, le partenaire se
 * sert le premier sur la commission partageable. Les deux assiettes diffèrent ; le
 * règlement, lui, est le même.
 *
 * Le partenaire envoie SA note de débit : il facture le cabinet, le cabinet lui reverse et
 * garde la pièce. C'est ce qui a permis d'unifier — auparavant sa rétro se facturait par
 * note de crédit et son « payé » se déduisait du prorata des règlements, sans qu'aucun
 * enregistrement de versement n'existe pour lui.
 *
 * ── CE QUE CE N'EST PAS ─────────────────────────────────────────────────────────────
 * Ce n'est ni une note de débit ni une note de crédit : ce circuit ne passe ni par Note,
 * ni par Article, ni par Paiement. Le « payé » et le « solde » d'une rétro se lisent donc
 * ici, en clair, sans prorata de note.
 *
 * ── LA MAILLE : L'ÉCHÉANCE ──────────────────────────────────────────────────────────
 * `tranche` dit QUAND, `avenant` dit SUR QUOI. La prime ET la commission se paient par
 * tranche : c'est à ce rythme que l'intermédiaire est rémunéré, et c'est pourquoi le fait
 * s'y rattache. Les deux liens coexistent, à charge pour l'applicatif de tenir l'invariant
 * `tranche.cotation === avenant.cotation`.
 *
 * ── LE LOT ──────────────────────────────────────────────────────────────────────────
 * Un virement unique couvrant dix échéances produit DIX lignes partageant le même
 * `lotReference`. Aucune entité d'en-tête n'a été créée pour cela : elle ne porterait
 * rien que la clé ne porte déjà. En échange, le solde reste exact échéance par échéance —
 * ce dont le rapport de production a besoin — sans aucun algorithme d'imputation, et la
 * comptabilité regroupe les lignes d'un même lot en UNE écriture, pour que le journal se
 * rapproche du relevé bancaire ligne à ligne.
 *
 * ── COMPTABILITÉ ────────────────────────────────────────────────────────────────────
 * L'écriture SYSCOHADA suit le BÉNÉFICIAIRE, pas le type d'enregistrement :
 *  — agent interne (un salarié)        → D 6611 Appointements, salaires et commissions ;
 *  — partenaire externe (intermédiaire) → D 632 Rétrocommissions ;
 * au crédit, la trésorerie (521 Banques, ou 571 Caisse à défaut de compte). Rien n'est
 * persisté : la comptabilité de ce projet est dérivée de ses données transactionnelles —
 * cf. CourtierEcritureComptableService.
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
    #[Groups(['list:read'])]
    private ?Invite $agent = null;

    /**
     * LE BÉNÉFICIAIRE PARTENAIRE, en XOR avec l'agent : l'un OU l'autre, jamais les deux,
     * jamais aucun.
     *
     * Le partenaire externe envoie SA note de débit : il facture le cabinet, le cabinet lui
     * reverse et garde la pièce. Son circuit de règlement est donc celui de l'agent, et
     * c'est ce qui permet de tenir les deux familles sur un seul enregistrement, une seule
     * liste, un seul écran.
     *
     * Même patron que `ConditionPartage`, qui porte déjà ce XOR : l'invariant est refusé en
     * 422 côté applicatif, un champ de formulaire non mappé pilotant la visibilité des deux
     * sélecteurs.
     */
    #[ORM\ManyToOne(inversedBy: 'reversementsRetro')]
    #[Groups(['list:read'])]
    private ?Partenaire $partenaire = null;

    /**
     * L'AFFAIRE réglée. Elle dit SUR QUOI porte le versement.
     *
     * Nullable depuis le passage à la maille de la tranche : les reversements ventilés par
     * la migration portent toujours leur avenant, mais une cotation peut compter plusieurs
     * avenants et la tranche n'en désigne aucun en particulier. Quand l'affaire est
     * connue, l'invariant `tranche.cotation === avenant.cotation` doit tenir.
     */
    #[ORM\ManyToOne(inversedBy: 'reversementsRetroAgent')]
    #[Groups(['list:read'])]
    private ?Avenant $avenant = null;

    /**
     * LA MAILLE DU FAIT : la tranche réglée. Elle dit QUAND.
     *
     * La prime ET la commission se paient par tranche ; c'est donc à ce rythme que
     * l'intermédiaire — agent interne ou partenaire externe — est rémunéré. Le dû était
     * déjà proratisé par tranche (`TrancheIndicatorStrategy::retroAgentDue`) tandis que le
     * versé restait accroché à l'avenant : dû et payé ne se comparaient donc jamais à la
     * même maille, et la colonne « rétro reversée » d'une tranche était indérivable.
     *
     * Nullable le temps de la transition : les lignes antérieures à la ventilation n'ont
     * pas de tranche, et la lecture sait encore les compter par leur avenant.
     */
    #[ORM\ManyToOne(inversedBy: 'reversementsRetroAgent')]
    #[Groups(['list:read'])]
    private ?Tranche $tranche = null;

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

    public function getPartenaire(): ?Partenaire
    {
        return $this->partenaire;
    }

    public function setPartenaire(?Partenaire $partenaire): static
    {
        $this->partenaire = $partenaire;

        return $this;
    }

    /**
     * LE NOM DU BÉNÉFICIAIRE, quelle que soit sa famille.
     *
     * Source unique pour tout ce qui l'affiche — liste, fiche, écriture comptable,
     * réponse de l'assistant. Sans elle, chaque surface refait le XOR et l'une d'elles
     * finira par n'en traiter qu'une moitié.
     */
    public function beneficiaireNom(): string
    {
        return (string) ($this->agent?->getNom() ?? $this->partenaire?->getNom() ?? 'N/A');
    }

    public function getBeneficiaire(): Partenaire|Invite|null
    {
        return $this->agent ?? $this->partenaire;
    }

    /**
     * UN REVERSEMENT VA À UN BÉNÉFICIAIRE, ET À UN SEUL.
     *
     * Sans agent ni partenaire, il ne verse à personne. Avec les deux, on ne saurait
     * pas quelle dette il éteint — ni quelle écriture comptable il produit, 6611 et
     * 632 ne se confondant pas. Trancher en silence ferait perdre de l'argent à
     * quelqu'un ; le refus est donc explicite (422), même règle et même forme que
     * ConditionPartage::estValide().
     */
    public function estValide(): bool
    {
        return ($this->agent !== null) !== ($this->partenaire !== null);
    }

    /**
     * L'ÉCHÉANCE ET L'AFFAIRE RELÈVENT-ELLES DE LA MÊME COTATION ?
     *
     * `Tranche` et `Avenant` sont tous deux enfants de `Cotation` : rien dans le schéma
     * n'empêche de les prendre dans deux affaires différentes. Le versement porterait
     * alors sur une affaire et s'imputerait à l'échéance d'une autre — le solde des
     * deux serait faux, et aucune erreur ne le dirait.
     *
     * Vraie quand l'un des deux manque : la maille est alors simplement moins précise,
     * ce qui est le cas des lignes antérieures au passage à l'échéance.
     */
    public function mailleCoherente(): bool
    {
        $cotationTranche = $this->tranche?->getCotation()?->getId();
        $cotationAvenant = $this->avenant?->getCotation()?->getId();

        return $cotationTranche === null || $cotationAvenant === null
            || $cotationTranche === $cotationAvenant;
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

    /**
     * La COTATION réglée, quelle que soit la maille renseignée.
     *
     * Source unique pour tout ce qui raisonne à l'affaire : la tranche la porte, et les
     * lignes antérieures à la ventilation la tiennent encore de leur avenant. Sans ce
     * point d'accès, chaque lecteur réinventerait le repli et l'un d'eux l'oublierait.
     */
    public function getCotation(): ?Cotation
    {
        return $this->tranche?->getCotation() ?? $this->avenant?->getCotation();
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
