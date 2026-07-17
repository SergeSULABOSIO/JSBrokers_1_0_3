<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\TrancheRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: TrancheRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Tranche
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

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $montantFlat = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?float $pourcentage = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $payableAt = null;

    #[ORM\ManyToOne(inversedBy: 'tranches')]
    private ?Cotation $cotation = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?\DateTimeImmutable $echeanceAt = null;

    /**
     * @var Collection<int, Article>
     */
    #[ORM\OneToMany(targetEntity: Article::class, mappedBy: 'tranche')]
    private Collection $articles;

    /**
     * @var Collection<int, PaiementPrime> Signalements de paiement de la prime par
     *      l'assuré (encaissée par l'ASSUREUR — jamais la trésorerie du courtier) :
     *      trace déclarative qui rend la commission de courtage exigible.
     */
    #[ORM\OneToMany(targetEntity: PaiementPrime::class, mappedBy: 'tranche', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $paiementsPrime;

    #[Groups(['list:read'])]
    public ?string $contexteParent = null;

    #[Groups(['list:read'])]
    public ?string $ageTranche = null;

    #[Groups(['list:read'])]
    public ?string $joursRestantsAvantEcheance = null;

    #[Groups(['list:read'])]
    public ?float $pourcentageAffiche = null;

    #[Groups(['list:read'])]
    public ?string $clientNom = null;

    #[Groups(['list:read'])]
    public ?string $cotationNom = null;

    #[Groups(['list:read'])]
    public ?string $nomCompletAvecStatut = null;

    // NOUVEAU : Attributs liés à la police
    #[Groups(['list:read'])]
    public ?string $referencePolice = null;

    #[Groups(['list:read'])]
    public ?string $periodeCouverture = null;

    #[Groups(['list:read'])]
    public ?string $assureurNom = null;

    // NOUVEAU : Attributs calculés spécifiques (Miroir de RevenuPourCourtier + Taux Tranche)
    #[Groups(['list:read'])]
    public ?float $tauxTranche = null;

    #[Groups(['list:read'])]
    public ?float $primeTranche = null;

    #[Groups(['list:read'])]
    public ?float $primePayee = null;

    #[Groups(['list:read'])]
    public ?float $primeSoldeDue = null;

    #[Groups(['list:read'])]
    public ?float $montantCalculeHT = null;

    #[Groups(['list:read'])]
    public ?float $montantCalculeTTC = null;

    #[Groups(['list:read'])]
    public ?string $descriptionCalcul = null;

    #[Groups(['list:read'])]
    public ?float $montant_du = null;

    #[Groups(['list:read'])]
    public ?float $montant_paye = null;

    #[Groups(['list:read'])]
    public ?float $solde_restant_du = null;

    #[Groups(['list:read'])]
    public ?float $montantPur = null;

    #[Groups(['list:read'])]
    public ?float $partPartenaire = null;

    #[Groups(['list:read'])]
    public ?float $retroCommission = null;

    #[Groups(['list:read'])]
    public ?float $reserve = null;

    #[Groups(['list:read'])]
    public ?float $retroCommissionReversee = null;

    #[Groups(['list:read'])]
    public ?float $retroCommissionSolde = null;

    #[Groups(['list:read'])]
    public ?float $taxeCourtierMontant = null;

    #[Groups(['list:read'])]
    public ?float $taxeCourtierTaux = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurMontant = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurTaux = null;

    #[Groups(['list:read'])]
    public ?string $estPartageable = null;

    #[Groups(['list:read'])]
    public ?float $taxeCourtierPayee = null;

    #[Groups(['list:read'])]
    public ?float $taxeCourtierSolde = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurPayee = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurSolde = null;

    // NOUVEAU : 5 Attributs calculés supplémentaires pour le suivi
    #[Groups(['list:read'])]
    public ?string $statutPaiement = null;

    #[Groups(['list:read'])]
    public ?float $tauxAvancement = null;

    #[Groups(['list:read'])]
    public ?float $resteAPayer = null;

    #[Groups(['list:read'])]
    public ?string $retardPaiement = null;

    #[Groups(['list:read'])]
    public ?\DateTimeInterface $dateDernierEncaissement = null;

    // Urgence de recouvrement (prime et/ou commission à collecter) : libellé affiché
    // en badge sur la liste + niveau technique (classe CSS / restitution assistant IA).
    #[Groups(['list:read'])]
    public ?string $urgenceRecouvrement = null;

    #[Groups(['list:read'])]
    public ?string $urgenceNiveau = null;

    // Rétrocommission partenaire exigible (solde dû, commission partageable encaissée) :
    // montant + libellé du badge « Rétro partenaire à payer » de la liste.
    #[Groups(['list:read'])]
    public ?float $retroCommissionExigible = null;

    #[Groups(['list:read'])]
    public ?string $retroAPayerAffiche = null;

    // Commission de courtage exigible auprès de l'assureur (prime payée par l'assuré —
    // facturée OU signalée via PaiementPrime — et commission non collectée).
    #[Groups(['list:read'])]
    public ?float $commissionExigible = null;

    #[Groups(['list:read'])]
    public ?string $commissionExigibleAffiche = null;

    // Cumul des paiements de prime signalés (déclaratif, hors trésorerie courtier).
    #[Groups(['list:read'])]
    public ?float $primeDeclareePayee = null;

    // Indicateurs d'affichage de la liste (taxes/commission/rétro-commission formatées) :
    // déclarés pour éviter les propriétés dynamiques (dépréciées en PHP 8.2).
    #[Groups(['list:read'])]
    public ?string $clientDescription = null;

    #[Groups(['list:read'])]
    public ?string $risqueDescription = null;

    #[Groups(['list:read'])]
    public ?string $taxeCourtierAffichee = null;

    #[Groups(['list:read'])]
    public ?string $taxeAssureurAffichee = null;

    #[Groups(['list:read'])]
    public ?string $commissionTTCAffichee = null;

    #[Groups(['list:read'])]
    public ?string $retroCommissionAffichee = null;

    public function __construct()
    {
        $this->articles = new ArrayCollection();
        $this->paiementsPrime = new ArrayCollection();
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

    public function getMontantFlat(): ?float
    {
        return $this->montantFlat;
    }

    public function setMontantFlat(?float $montantFlat): static
    {
        $this->montantFlat = $montantFlat;

        return $this;
    }

    public function getPourcentage(): ?float
    {
        return $this->pourcentage;
    }

    public function setPourcentage(?float $pourcentage): static
    {
        $this->pourcentage = $pourcentage;

        return $this;
    }

    public function getPayableAt(): ?\DateTimeImmutable
    {
        return $this->payableAt;
    }

    public function setPayableAt(\DateTimeImmutable $payableAt): static
    {
        $this->payableAt = $payableAt;

        return $this;
    }

    public function getCotation(): ?Cotation
    {
        return $this->cotation;
    }

    public function setCotation(?Cotation $cotation): static
    {
        $this->cotation = $cotation;

        return $this;
    }

    public function getEcheanceAt(): ?\DateTimeImmutable
    {
        return $this->echeanceAt;
    }

    public function setEcheanceAt(?\DateTimeImmutable $echeanceAt): static
    {
        $this->echeanceAt = $echeanceAt;

        return $this;
    }

    /**
     * @return Collection<int, Article>
     */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    /**
     * @return Collection<int, PaiementPrime>
     */
    public function getPaiementsPrime(): Collection
    {
        return $this->paiementsPrime;
    }

    public function addPaiementsPrime(PaiementPrime $paiementPrime): static
    {
        if (!$this->paiementsPrime->contains($paiementPrime)) {
            $this->paiementsPrime->add($paiementPrime);
            $paiementPrime->setTranche($this);
        }

        return $this;
    }

    public function removePaiementsPrime(PaiementPrime $paiementPrime): static
    {
        if ($this->paiementsPrime->removeElement($paiementPrime)) {
            if ($paiementPrime->getTranche() === $this) {
                $paiementPrime->setTranche(null);
            }
        }

        return $this;
    }

    public function addArticle(Article $article): static
    {
        if (!$this->articles->contains($article)) {
            $this->articles->add($article);
            $article->setTranche($this);
        }

        return $this;
    }

    public function removeArticle(Article $article): static
    {
        if ($this->articles->removeElement($article)) {
            // set the owning side to null (unless already changed)
            if ($article->getTranche() === $this) {
                $article->setTranche(null);
            }
        }

        return $this;
    }

    public function __toString()
    {
        return ($this->cotation != null ? $this->cotation->getNom() : "") . " / " . $this->id . " / " . $this->nom;
    }
}
