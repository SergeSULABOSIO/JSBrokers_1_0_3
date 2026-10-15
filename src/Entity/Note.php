<?php

namespace App\Entity;

use App\Repository\NoteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Entity\Traits\AuditableTrait;
use Symfony\Component\Serializer\Annotation\Groups;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteRepository::class)]
// UNE RÉFÉRENCE N'APPARTIENT QU'À UNE NOTE DU CABINET (décision du 2026-10-14). Le refus
// lisible vient d'avant (ChampsObligatoiresInspector::incoherencesMetier) ; l'index est le
// dernier rempart, contre deux écritures simultanées.
#[ORM\UniqueConstraint(name: 'uniq_note_entreprise_reference', columns: ['entreprise_id', 'reference'])]
#[ORM\HasLifecycleCallbacks]
class Note implements OwnerAwareInterface
{
    use AuditableTrait;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;
    
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    /**
     * @var Collection<int, Article>
     */
    #[ORM\OneToMany(targetEntity: Article::class, mappedBy: 'note', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $articles;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $type = null;
    public const TYPE_NULL = -1;
    public const TYPE_NOTE_DE_DEBIT = 0;
    public const TYPE_NOTE_DE_CREDIT = 1;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $addressedTo = null;
    public const TO_NULL = -1;
    public const TO_CLIENT = 0;
    public const TO_ASSUREUR = 1;
    public const TO_PARTENAIRE = 2;
    public const TO_AUTORITE_FISCALE = 3;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'notes')]
    #[Groups(['list:read'])]
    private ?Client $client = null;

    #[ORM\ManyToOne(inversedBy: 'notes')]
    #[Groups(['list:read'])]
    private ?Partenaire $partenaire = null;

    #[ORM\ManyToOne(inversedBy: 'notes')]
    #[Groups(['list:read'])]
    private ?Assureur $assureur = null;

    /**
     * @var Collection<int, CompteBancaire>
     */
    #[ORM\ManyToMany(targetEntity: CompteBancaire::class, inversedBy: 'notes')]
    private Collection $comptes;

    /**
     * @var Collection<int, Paiement>
     */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'note', cascade: ['detach', 'refresh', 'persist', 'remove'], orphanRemoval: true)]
    private Collection $paiements;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $reference = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?bool $validated = null;

    #[ORM\ManyToOne(inversedBy: 'notes')]
    #[Groups(['list:read'])]
    private ?AutoriteFiscale $autoritefiscale = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $signature = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $signedBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $titleSignedBy = null;

    /** La date d'ENVOI (suivi) : une information, sans effet comptable — voir $emiseLe. */
    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $sentAt = null;

    /**
     * LA DATE D'ÉMISSION : l'instant de la validation, posé en même temps que le numéro
     * définitif ({@see \App\EventListener\NumerotationDesNotesListener}) et jamais saisi.
     * C'est elle qui date la créance au journal et qui s'imprime sur la pièce. Le numéro
     * suivant de la série est pris sous verrou APRÈS le commit de celui-ci : une note
     * n'est donc jamais émise avant la précédente de sa série (règle OHADA). Null pour un
     * brouillon.
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $emiseLe = null;

    /**
     * LES MONTANTS FIGÉS (règle OHADA, 2026-10-15) : posés à la validation — ou à
     * l'import d'une note de reprise —, ils ne se recalculent plus. Null tant que la note
     * n'est pas figée ({@see \App\Service\Note\FigeageDeNote}).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $figeeLe = null;

    #[ORM\Column(nullable: true)]
    private ?float $montantHtFige = null;

    #[ORM\Column(nullable: true)]
    private ?float $montantTtcFige = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $libelleTaxeFige = null;

    #[ORM\Column(nullable: true)]
    private ?float $tauxTaxeFige = null;

    /**
     * D'OÙ VIENT LA NOTE. Une note de REPRISE retrace une commission déjà encaissée dans
     * l'ancien système : ce n'est pas une facture émise par Joseara. Elle reste hors série
     * (référence « REPRISE-… »), ne fait naître aucune créance au journal, mais elle est
     * figée et intangible comme une note validée (décision de Serge du 2026-10-15).
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $origine = self::ORIGINE_JOSEARA;
    public const ORIGINE_JOSEARA = 0;
    public const ORIGINE_REPRISE = 1;

    #[ORM\ManyToOne(inversedBy: 'notes')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Bordereau $bordereau = null;

    // Attributs calculés
    #[Groups(['list:read'])]
    public ?string $typeString = null;

    #[Groups(['list:read'])]
    public ?string $addressedToString = null;

    #[Groups(['list:read'])]
    public ?float $montantTotal = null;

    #[Groups(['list:read'])]
    public ?float $montantPaye = null;

    #[Groups(['list:read'])]
    public ?float $solde = null;

    #[Groups(['list:read'])]
    public ?string $statutPaiement = null;

    /**
     * Il reste quelque chose à encaisser — condition d'affichage de l'action
     * « Signaler le règlement ». Déclarée ici, et non posée dynamiquement : les
     * propriétés dynamiques sont dépréciées, et `Note` fait partie des entités déjà
     * assainies.
     */
    #[Groups(['list:read'])]
    public ?bool $aUnSoldeDu = null;

    /** Condition d'affichage de l'action « Valider la note » (calculée, non persistée). */
    #[Groups(['list:read'])]
    public ?bool $aValider = null;

    /**
     * Badge de PROVENANCE : « Bordereau » quand la note est issue d'un bordereau de
     * production, null sinon. Le rendu d'une ligne n'affiche un badge que si sa valeur
     * n'est pas vide — c'est ainsi qu'une note ordinaire reste muette.
     */
    #[Groups(['list:read'])]
    public ?string $bordereauAffiche = null;

    /**
     * La référence du bordereau d'origine, pour la ligne secondaire : savoir qu'une note
     * vient d'un bordereau ne dit pas DUQUEL.
     *
     * ⚠ Code PLAT obligatoire : le rendu d'une ligne fait `attribute(entity, code)`, où un
     * chemin pointé (« bordereau.reference ») est lu comme un nom de propriété et fait
     * tomber la rubrique ENTIÈRE.
     */
    #[Groups(['list:read'])]
    public ?string $bordereauReference = null;

    #[Groups(['list:read'])]
    public ?float $montantTaxe = null;

    #[Groups(['list:read'])]
    public ?string $nomTaxe = null;

    #[Groups(['list:read'])]
    public ?float $tauxTaxe = null;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->articles = new ArrayCollection();
        $this->comptes = new ArrayCollection();
        $this->paiements = new ArrayCollection();
    }

    /**
     * @var Collection<int, Document> Pièces jointes de cette fiche.
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'note', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

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
            $document->setNote($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getNote() === $this) {
                $document->setNote(null);
            }
        }

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    /**
     * @return Collection<int, Article>
     */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    public function addArticle(Article $article): static
    {
        if (!$this->articles->contains($article)) {
            $this->articles->add($article);
            $article->setNote($this);
        }

        return $this;
    }

    public function removeArticle(Article $article): static
    {
        if ($this->articles->removeElement($article)) {
            // set the owning side to null (unless already changed)
            if ($article->getNote() === $this) {
                $article->setNote(null);
            }
        }

        return $this;
    }

    public function getType(): ?int
    {
        return $this->type;
    }

    public function getTypeString(): string
    {
        return match ($this->type) {
            self::TYPE_NOTE_DE_DEBIT  => 'Note de Débit',
            self::TYPE_NOTE_DE_CREDIT => 'Note de Crédit',
            default                   => 'Note',
        };
    }

    public function setType(int $type): static
    {
        $this->type = $type;

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

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

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

    public function getAssureur(): ?Assureur
    {
        return $this->assureur;
    }

    public function setAssureur(?Assureur $assureur): static
    {
        $this->assureur = $assureur;

        return $this;
    }

    public function getAddressedTo(): ?int
    {
        return $this->addressedTo;
    }

    public function setAddressedTo(int $addressedTo): static
    {
        $this->addressedTo = $addressedTo;

        return $this;
    }

    /**
     * @return Collection<int, CompteBancaire>
     */
    public function getComptes(): Collection
    {
        return $this->comptes;
    }

    public function addCompte(CompteBancaire $compte): static
    {
        if (!$this->comptes->contains($compte)) {
            $this->comptes->add($compte);
        }

        return $this;
    }

    public function removeCompte(CompteBancaire $compte): static
    {
        $this->comptes->removeElement($compte);

        return $this;
    }

    /**
     * @return Collection<int, Paiement>
     */
    public function getPaiements(): Collection
    {
        return $this->paiements;
    }

    public function addPaiement(Paiement $paiement): static
    {
        if (!$this->paiements->contains($paiement)) {
            $this->paiements->add($paiement);
            $paiement->setNote($this);
        }

        return $this;
    }

    public function removePaiement(Paiement $paiement): static
    {
        if ($this->paiements->removeElement($paiement)) {
            // set the owning side to null (unless already changed)
            if ($paiement->getNote() === $this) {
                $paiement->setNote(null);
            }
        }

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    /**
     * La référence n'est jamais saisie : un brouillon reçoit une référence provisoire à
     * l'écriture, une note validée son numéro définitif
     * ({@see \App\EventListener\NumerotationDesNotesListener}).
     */
    public function setReference(?string $reference): static
    {
        $reference = $reference !== null ? trim($reference) : null;
        $this->reference = $reference === '' ? null : $reference;

        return $this;
    }

    public function isValidated(): ?bool
    {
        return $this->validated;
    }

    public function setValidated(bool $validated): static
    {
        $this->validated = $validated;

        return $this;
    }

    public function getAutoritefiscale(): ?AutoriteFiscale
    {
        return $this->autoritefiscale;
    }

    public function setAutoritefiscale(?AutoriteFiscale $autoritefiscale): static
    {
        $this->autoritefiscale = $autoritefiscale;

        return $this;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function setSignature(string $signature): static
    {
        $this->signature = $signature;

        return $this;
    }

    public function getSignedBy(): ?string
    {
        return $this->signedBy;
    }

    public function setSignedBy(?string $signedBy): static
    {
        $this->signedBy = $signedBy;

        return $this;
    }

    public function getTitleSignedBy(): ?string
    {
        return $this->titleSignedBy;
    }

    public function setTitleSignedBy(?string $titleSignedBy): static
    {
        $this->titleSignedBy = $titleSignedBy;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getEmiseLe(): ?\DateTimeImmutable
    {
        return $this->emiseLe;
    }

    /** Réservé à la numérotation : la date d'émission ne se saisit pas. */
    public function setEmiseLe(?\DateTimeImmutable $emiseLe): static
    {
        $this->emiseLe = $emiseLe;

        return $this;
    }

    /** Ses montants sont-ils figés (note validée, ou note de reprise) ? */
    public function estFigee(): bool
    {
        return $this->figeeLe !== null;
    }

    public function getFigeeLe(): ?\DateTimeImmutable
    {
        return $this->figeeLe;
    }

    public function getMontantHtFige(): ?float
    {
        return $this->montantHtFige;
    }

    public function getMontantTtcFige(): ?float
    {
        return $this->montantTtcFige;
    }

    public function getLibelleTaxeFige(): ?string
    {
        return $this->libelleTaxeFige;
    }

    public function getTauxTaxeFige(): ?float
    {
        return $this->tauxTaxeFige;
    }

    /** Réservé au figeage ({@see \App\Service\Note\FigeageDeNote}) : rien de tout cela ne se saisit. */
    public function figer(float $ht, float $ttc, ?string $libelleTaxe, ?float $tauxTaxe, \DateTimeImmutable $le): static
    {
        $this->montantHtFige = $ht;
        $this->montantTtcFige = $ttc;
        $this->libelleTaxeFige = $libelleTaxe;
        $this->tauxTaxeFige = $tauxTaxe;
        $this->figeeLe = $le;

        return $this;
    }

    public function getOrigine(): int
    {
        return $this->origine;
    }

    public function setOrigine(int $origine): static
    {
        $this->origine = $origine;

        return $this;
    }

    public function estDeReprise(): bool
    {
        return $this->origine === self::ORIGINE_REPRISE;
    }

    /** Un brouillon : pas encore validé, il n'a ni numéro définitif ni valeur comptable. */
    public function estBrouillon(): bool
    {
        return $this->validated !== true;
    }

    public function getBordereau(): ?Bordereau
    {
        return $this->bordereau;
    }

    public function setBordereau(?Bordereau $bordereau): static
    {
        $this->bordereau = $bordereau;

        return $this;
    }
}
