<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\PisteRepository;
use Doctrine\Common\Collections\Collection;
use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\CalculatedIndicatorsTrait;
use Symfony\Component\Serializer\Annotation\Groups;
use Doctrine\Common\Collections\ArrayCollection;

#[ORM\Entity(repositoryClass: PisteRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Piste implements OwnerAwareInterface
{
    use AuditableTrait;
    use CalculatedIndicatorsTrait;

    //Type d'Avenant
    public const AVENANT_SOUSCRIPTION = 0;
    public const AVENANT_INCORPORATION = 1;
    public const AVENANT_PROROGATION = 2;
    public const AVENANT_ANNULATION = 3;
    public const AVENANT_RESILIATION = 4;
    public const AVENANT_RENOUVELLEMENT = 5;

    //Conditions de renouvellement
    public const RENEWAL_CONDITION_RENEWABLE = 0;
    public const RENEWAL_CONDITION_ADJUSTABLE_AT_EXPIRY = 1;
    public const RENEWAL_CONDITION_ONCE_OFF_AND_EXTENDABLE = 2;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;
    
    #[ORM\ManyToOne(inversedBy: 'pistes')]
    private ?Risque $risque = null;

    #[ORM\ManyToOne(inversedBy: 'pistes')]
    #[Groups(['list:read'])]
    private ?Client $client = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $primePotentielle = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $commissionPotentielle = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $typeAvenant = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $descriptionDuRisque = null;

    /**
     * @var Collection<int, Cotation>
     */
    #[ORM\OneToMany(targetEntity: Cotation::class, mappedBy: 'piste', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $cotations;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'piste', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    /**
     * @var Collection<int, Tache>
     */
    #[ORM\OneToMany(targetEntity: Tache::class, mappedBy: 'piste', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $taches;

    /**
     * L'INTERMÉDIAIRE EXTERNE DE CETTE AFFAIRE — un seul, et c'est délibéré.
     *
     * Le champ acceptait plusieurs apporteurs, mais le moteur n'en a jamais retenu qu'un :
     * `getCotationPartenaire()` prenait le premier venu d'une table de liaison, laquelle
     * n'a aucun ordre défini. L'écran promettait donc un partage multi-apporteurs que le
     * calcul ne savait pas faire, et le tableau de bord comptait un même avenant sous
     * chacun d'eux.
     *
     * Il est aussi la CLÉ D'ENTRÉE du partage : sans intermédiaire (ni ici, ni sur le
     * client), la part partenaire vaut zéro et les conditions propres à l'affaire ne sont
     * même pas consultées.
     */
    #[ORM\ManyToOne(inversedBy: 'pistes')]
    private ?Partenaire $partenaire = null;

    /**
     * @var Collection<int, ConditionPartage>
     */
    #[ORM\OneToMany(targetEntity: ConditionPartage::class, mappedBy: 'piste', cascade: ['persist', 'remove'])]
    private Collection $conditionsPartageExceptionnelles;

    /**
     * @var Collection<int, ConditionPartage> Conditions de partage au profit d'AGENTS
     *      INTERNES, rattachées à cette affaire.
     *
     * Côté PROPRIÉTAIRE de la relation : c'est ce qui permet au moteur
     * de mutation de la poser par simple liste d'identifiants — donc à l'assistant de
     * reconduire ces conditions sur une piste dérivée sans machinerie dédiée.
     *
     * Ni cascade persist ni cascade remove, VOLONTAIREMENT : la condition ne appartient
     * pas à la piste, elle lui est prêtée. Supprimer une affaire ne doit pas emporter une
     * règle de rémunération qui sert peut-être à dix autres.
     */
    #[ORM\ManyToMany(targetEntity: ConditionPartage::class, inversedBy: 'pistesAffectees')]
    private Collection $conditionsPartageAgent;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $exercice = null;

    #[ORM\OneToOne(cascade: ['persist', 'remove'])]
    private ?Avenant $avenantDeBase = null;

    // Le renouvelable est le cas NORMAL d'une police : sans défaut, ce champ restait
    // vide à toute création et la condition de renouvellement d'une affaire était
    // indéterminée. `typeAvenant`, lui, n'a volontairement pas de défaut — c'est un
    // discriminant (cf. ChampsObligatoiresInspector::CHOIX_METIER_REQUIS).
    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?int $renewalCondition = self::RENEWAL_CONDITION_RENEWABLE;

    #[ORM\Column(options: ['default' => false])]
    private bool $closed = false;

    public function __construct()
    {
        $this->taches = new ArrayCollection();
        $this->cotations = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->conditionsPartageExceptionnelles = new ArrayCollection();
        $this->conditionsPartageAgent = new ArrayCollection();
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

    public function getPrimePotentielle(): ?float
    {
        return $this->primePotentielle;
    }

    public function setPrimePotentielle(?float $primePotentielle): static
    {
        $this->primePotentielle = $primePotentielle;

        return $this;
    }

    public function getCommissionPotentielle(): ?float
    {
        return $this->commissionPotentielle;
    }

    public function setCommissionPotentielle(?float $commissionPotentielle): static
    {
        $this->commissionPotentielle = $commissionPotentielle;

        return $this;
    }

    public function getRisque(): ?Risque
    {
        return $this->risque;
    }

    public function setRisque(?Risque $risque): static
    {
        $this->risque = $risque;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom;
    }

    public function getClient(): ?Client
    {
        try {
            // Force le chargement du proxy en accédant à une propriété.
            // Si l'entité liée n'existe pas, une EntityNotFoundException sera levée.
            if ($this->client) {
                $this->client->getNom(); // Cet appel force le chargement.
            }
        } catch (\Doctrine\ORM\EntityNotFoundException $e) {
            $this->client = null;
        }
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    /**
     * @return Collection<int, Tache>
     */
    public function getTaches(): Collection
    {
        return $this->taches;
    }

    public function addTache(Tache $tache): static
    {
        if (!$this->taches->contains($tache)) {
            $this->taches->add($tache);
            $tache->setPiste($this);
        }

        return $this;
    }

    public function removeTache(Tache $tache): static
    {
        if ($this->taches->removeElement($tache)) {
            // set the owning side to null (unless already changed)
            if ($tache->getPiste() === $this) {
                $tache->setPiste(null);
            }
        }

        return $this;
    }

    public function getTypeAvenant(): ?int
    {
        return $this->typeAvenant;
    }

    public function setTypeAvenant(int $typeAvenant): static
    {
        $this->typeAvenant = $typeAvenant;

        return $this;
    }

    public function getDescriptionDuRisque(): ?string
    {
        return $this->descriptionDuRisque;
    }

    public function setDescriptionDuRisque(string $descriptionDuRisque): static
    {
        $this->descriptionDuRisque = $descriptionDuRisque;

        return $this;
    }

    /**
     * @return Collection<int, Cotation>
     */
    public function getCotations(): Collection
    {
        return $this->cotations;
    }

    public function addCotation(Cotation $cotation): static
    {
        if (!$this->cotations->contains($cotation)) {
            $this->cotations->add($cotation);
            $cotation->setPiste($this);
        }

        return $this;
    }

    public function removeCotation(Cotation $cotation): static
    {
        if ($this->cotations->removeElement($cotation)) {
            // set the owning side to null (unless already changed)
            if ($cotation->getPiste() === $this) {
                $cotation->setPiste(null);
            }
        }

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
            $document->setPiste($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getPiste() === $this) {
                $document->setPiste(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Partenaire>
     */
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
     * @return Collection<int, ConditionPartage>
     */
    public function getConditionsPartageExceptionnelles(): Collection
    {
        return $this->conditionsPartageExceptionnelles;
    }

    public function addConditionsPartageExceptionnelle(ConditionPartage $conditionsPartageExceptionnelle): static
    {
        if (!$this->conditionsPartageExceptionnelles->contains($conditionsPartageExceptionnelle)) {
            $this->conditionsPartageExceptionnelles->add($conditionsPartageExceptionnelle);
            $conditionsPartageExceptionnelle->setPiste($this);
        }

        return $this;
    }

    public function removeConditionsPartageExceptionnelle(ConditionPartage $conditionsPartageExceptionnelle): static
    {
        if ($this->conditionsPartageExceptionnelles->removeElement($conditionsPartageExceptionnelle)) {
            // set the owning side to null (unless already changed)
            if ($conditionsPartageExceptionnelle->getPiste() === $this) {
                $conditionsPartageExceptionnelle->setPiste(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ConditionPartage> Conditions de partage RATTACHÉES à cette
     *         affaire au profit d'agents internes (partagées, jamais clonées).
     */
    public function getConditionsPartageAgent(): Collection
    {
        return $this->conditionsPartageAgent;
    }

    public function addConditionsPartageAgent(ConditionPartage $condition): static
    {
        if (!$this->conditionsPartageAgent->contains($condition)) {
            $this->conditionsPartageAgent->add($condition);
        }

        return $this;
    }

    public function removeConditionsPartageAgent(ConditionPartage $condition): static
    {
        $this->conditionsPartageAgent->removeElement($condition);

        return $this;
    }

    public function getExercice(): ?int
    {
        return $this->exercice;
    }

    public function setExercice(int $exercice): static
    {
        $this->exercice = $exercice;

        return $this;
    }

    public function getAvenantDeBase(): ?Avenant
    {
        return $this->avenantDeBase;
    }

    public function setAvenantDeBase(?Avenant $avenantDeBase): static
    {
        $this->avenantDeBase = $avenantDeBase;

        return $this;
    }

    public function getRenewalCondition(): ?int
    {
        return $this->renewalCondition;
    }

    public function setRenewalCondition(?int $renewalCondition): static
    {
        $this->renewalCondition = $renewalCondition;

        return $this;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function setClosed(bool $closed): static
    {
        $this->closed = $closed;

        return $this;
    }
}
