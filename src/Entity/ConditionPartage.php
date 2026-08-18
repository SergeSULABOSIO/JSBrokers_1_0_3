<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;
use App\Repository\ConditionPartageRepository;
use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\CalculatedIndicatorsTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: ConditionPartageRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ConditionPartage
{
    use AuditableTrait;
    use CalculatedIndicatorsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    // Sans seuil = la condition s'applique inconditionnellement, la formule la plus
    // simple et la plus sûre à supposer. Colonne NOT NULL.
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $formule = self::FORMULE_NE_SAPPLIQUE_PAS_SEUIL;
    public const FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL = 0;
    public const FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL = 1;
    public const FORMULE_NE_SAPPLIQUE_PAS_SEUIL = 2;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?float $seuil = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $taux = null;

    #[ORM\ManyToOne(inversedBy: 'conditionPartages')]
    #[Groups(['list:read'])]
    private ?Partenaire $partenaire = null;

    /**
     * LE BÉNÉFICIAIRE INTERNE — miroir exact de $partenaire, pour un agent du cabinet.
     *
     * Une condition rétrocède soit à un partenaire EXTERNE, soit à un agent INTERNE,
     * jamais aux deux (cf. estValide()). Le reste de la condition — taux, seuil, formule,
     * unité de mesure, ciblage des risques — s'applique à l'identique dans les deux cas :
     * c'est ce qui permet de récompenser un effort commercial interne avec exactement la
     * même grammaire qu'un accord d'apport externe, sans second mécanisme à maintenir.
     *
     * ⚠ L'agent BÉNÉFICIAIRE n'est pas le GESTIONNAIRE de l'affaire (Piste::invite, posé
     * par AuditableTrait). Un agent peut initier le premier contact puis confier la
     * gestion quotidienne à quelqu'un d'autre : il reste le bénéficiaire. Ne jamais
     * dériver l'un de l'autre.
     */
    #[ORM\ManyToOne(inversedBy: 'conditionsPartageAgent')]
    #[Groups(['list:read'])]
    private ?Invite $agent = null;

    /**
     * @var Collection<int, Piste> Les affaires sur lesquelles cette condition s'applique.
     *
     * POURQUOI UNE SECONDE RELATION VERS LA PISTE, à côté de $piste. Les deux ne disent
     * pas la même chose :
     *  - $piste (ManyToOne) = condition EXCEPTIONNELLE, propriété de cette piste-là, et
     *    CLONÉE au renouvellement (cf. ReconductionPartageService) ;
     *  - $pistesAffectees (ManyToMany) = condition PARTAGÉE, définie une fois puis
     *    rattachée à autant d'affaires qu'on veut, et RECONDUITE telle quelle (même
     *    identifiant) sur les pistes dérivées.
     * Une condition d'agent emprunte le second chemin : la règle « prime apporteur 15 % »
     * s'écrit une fois et se retrouve à l'identique sur chaque affaire qu'il apporte.
     * Fondre les deux relations aurait fait cloner les conditions partagées — donc perdre
     * la source unique que cette collection existe précisément pour offrir.
     */
    #[ORM\ManyToMany(targetEntity: Piste::class, mappedBy: 'conditionsPartageAgent')]
    private Collection $pistesAffectees;

    /**
     * @var Collection<int, Risque>
     */
    #[ORM\OneToMany(targetEntity: Risque::class, mappedBy: 'conditionPartage')]
    private Collection $produits;

    // « Pas de risques ciblés » = la condition ne discrimine aucun risque : le neutre.
    // Ce défaut ferme une violation d'intégrité atteignable depuis l'écran — la colonne
    // est NOT NULL alors que le champ du formulaire est `required: false`.
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $critereRisque = self::CRITERE_PAS_RISQUES_CIBLES;
    public const CRITERE_EXCLURE_TOUS_CES_RISQUES = 0;
    public const CRITERE_INCLURE_TOUS_CES_RISQUES = 1;
    public const CRITERE_PAS_RISQUES_CIBLES = 2;


    #[ORM\ManyToOne(inversedBy: 'conditionsPartageExceptionnelles')]
    #[Groups(['list:read'])]
    private ?Piste $piste = null;

    public const UNITE_SOMME_COMMISSION_PURE_RISQUE = 0;
    public const UNITE_SOMME_COMMISSION_PURE_CLIENT = 1;
    public const UNITE_SOMME_COMMISSION_PURE_PARTENAIRE = 2;
    /**
     * Toute la production du BÉNÉFICIAIRE sur l'exercice — pendant interne de
     * UNITE_SOMME_COMMISSION_PURE_PARTENAIRE, pour un intéressement à palier du genre
     * « 15 %, mais seulement au-delà de 10 000 apportés dans l'année ».
     *
     * ⚠ « Production de l'agent » = les affaires dont il est BÉNÉFICIAIRE (celles portant
     * une de ses conditions), jamais celles qu'il gère.
     */
    public const UNITE_SOMME_COMMISSION_PURE_AGENT = 3;
    // Unité de référence du partage : la commission pure du RISQUE.
    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?int $uniteMesure = self::UNITE_SOMME_COMMISSION_PURE_RISQUE;

    #[Groups(['list:read'])]
    public ?string $formule_string;

    #[Groups(['list:read'])]
    public ?string $critere_risque_string;

    #[Groups(['list:read'])]
    public ?string $unite_mesure_string;

    // NOUVEAU : Attributs calculés pour les indicateurs financiers et statistiques
    #[Groups(['list:read'])]
    public ?float $totalAssiette = null;

    #[Groups(['list:read'])]
    public ?float $totalRetroCommission = null;

    #[Groups(['list:read'])]
    public ?int $nombreDossiersConcernes = null;

    #[Groups(['list:read'])]
    public ?string $contexteParent = null;
  
    

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->produits = new ArrayCollection();
        $this->pistesAffectees = new ArrayCollection();
    }

    /**
     * @var Collection<int, Document> Pièces jointes de cette fiche.
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'conditionPartage', cascade: ['persist', 'remove'], orphanRemoval: true)]
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
            $document->setConditionPartage($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getConditionPartage() === $this) {
                $document->setConditionPartage(null);
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

    public function getFormule(): ?int
    {
        return $this->formule;
    }

    public function setFormule(int $formule): static
    {
        $this->formule = $formule;

        return $this;
    }

    public function getSeuil(): ?float
    {
        return $this->seuil;
    }

    public function setSeuil(float $seuil): static
    {
        $this->seuil = $seuil;

        return $this;
    }

    public function getTaux(): ?float
    {
        return $this->taux;
    }

    /**
     * Fraction (0..1) dérivée du taux stocké en POINTS (30 = 30 %) : source UNIQUE
     * pour tout calcul (assiette × fraction). Ne jamais multiplier une assiette par
     * getTaux() directement.
     */
    public function getFraction(): float
    {
        return ($this->taux ?? 0.0) / 100.0;
    }

    public function setTaux(?float $taux): static
    {
        $this->taux = $taux;

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
     * @return Collection<int, Risque>
     */
    public function getProduits(): Collection
    {
        return $this->produits;
    }

    public function addProduit(Risque $produit): static
    {
        if (!$this->produits->contains($produit)) {
            $this->produits->add($produit);
            $produit->setConditionPartage($this);
        }

        return $this;
    }

    public function removeProduit(Risque $produit): static
    {
        if ($this->produits->removeElement($produit)) {
            // set the owning side to null (unless already changed)
            if ($produit->getConditionPartage() === $this) {
                $produit->setConditionPartage(null);
            }
        }

        return $this;
    }

    public function getCritereRisque(): ?int
    {
        return $this->critereRisque;
    }

    public function setCritereRisque(int $critereRisque): static
    {
        $this->critereRisque = $critereRisque;

        return $this;
    }

    // public function getUnite(): ?int
    // {
    //     return $this->unite;
    // }

    // public function setUnite(int $unite): static
    // {
    //     $this->unite = $unite;

    //     return $this;
    // }

    public function getAgent(): ?Invite
    {
        return $this->agent;
    }

    public function setAgent(?Invite $agent): static
    {
        $this->agent = $agent;

        return $this;
    }

    /** Cette condition rétrocède-t-elle à un agent INTERNE plutôt qu'à un partenaire ? */
    public function estPourAgent(): bool
    {
        return $this->agent !== null;
    }

    /**
     * Le bénéficiaire de la rétrocommission, quel que soit son bord. Null tant que la
     * condition n'en désigne aucun — état incomplet que estValide() signale.
     */
    public function getBeneficiaire(): Partenaire|Invite|null
    {
        return $this->agent ?? $this->partenaire;
    }

    /**
     * Une condition rétrocède à UN bénéficiaire, et à un seul.
     *
     * Sans partenaire ni agent, elle ne partage avec personne : elle ne peut rien
     * calculer. Avec les deux, l'assiette à retenir serait ambiguë (celle du partenaire
     * ne porte que les revenus partageables, celle de l'agent porte ce qui reste au
     * cabinet APRÈS les partenaires) — et le choix silencieux de l'une ferait perdre de
     * l'argent à quelqu'un. La saisie est donc refusée dans les deux cas, en nommant
     * le champ (cf. le refus 422 du trait CRUD).
     */
    public function estValide(): bool
    {
        return ($this->agent !== null) !== ($this->partenaire !== null);
    }

    /**
     * @return Collection<int, Piste> Les affaires portant cette condition partagée.
     */
    public function getPistesAffectees(): Collection
    {
        return $this->pistesAffectees;
    }

    public function addPisteAffectee(Piste $piste): static
    {
        if (!$this->pistesAffectees->contains($piste)) {
            $this->pistesAffectees->add($piste);
            $piste->addConditionsPartageAgent($this);
        }

        return $this;
    }

    public function removePisteAffectee(Piste $piste): static
    {
        if ($this->pistesAffectees->removeElement($piste)) {
            $piste->removeConditionsPartageAgent($this);
        }

        return $this;
    }

    public function getPiste(): ?Piste
    {
        return $this->piste;
    }

    public function setPiste(?Piste $piste): static
    {
        $this->piste = $piste;

        return $this;
    }

    public function getUniteMesure(): ?int
    {
        return $this->uniteMesure;
    }

    public function setUniteMesure(?int $uniteMesure): static
    {
        $this->uniteMesure = $uniteMesure;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom ?? 'Nouvelle condition';
    }

    /**
     * La condition vise-t-elle cette affaire ?
     *
     * Deux rattachements, une seule question : la condition EXCEPTIONNELLE appartient à sa
     * piste, la condition PARTAGÉE (agent) figure dans la liste de ses affaires. Le
     * ciblage par risque reste à vérifier séparément — cf. sappliqueAuRisque().
     */
    public function viseLaPiste(?Piste $piste): bool
    {
        if ($piste === null) {
            return false;
        }

        return $this->piste === $piste || $this->pistesAffectees->contains($piste);
    }

    /**
     * Indique si cette condition de partage s'applique au risque donné.
     *
     * Règle unique (partagée par le calcul du taux de rétrocommission et par la
     * reconduction du partage sur les avenants dérivés) :
     *  - CRITERE_PAS_RISQUES_CIBLES : toujours applicable ;
     *  - CRITERE_INCLURE_TOUS_CES_RISQUES : applicable si le risque est ciblé ;
     *  - CRITERE_EXCLURE_TOUS_CES_RISQUES : applicable si le risque n'est PAS ciblé.
     */
    public function sappliqueAuRisque(?Risque $risque): bool
    {
        return match ($this->critereRisque) {
            self::CRITERE_PAS_RISQUES_CIBLES => true,
            self::CRITERE_INCLURE_TOUS_CES_RISQUES => $risque !== null && $this->produits->contains($risque),
            self::CRITERE_EXCLURE_TOUS_CES_RISQUES => $risque === null || !$this->produits->contains($risque),
            default => false,
        };
    }
}
