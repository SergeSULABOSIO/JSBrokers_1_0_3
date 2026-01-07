<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\RisqueRepository;
use Doctrine\Common\Collections\Collection;
use App\Entity\Traits\CalculatedIndicatorsTrait;
use Doctrine\Common\Collections\ArrayCollection;

#[ORM\Entity(repositoryClass: RisqueRepository::class)]
class Risque
{
    use CalculatedIndicatorsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 6)]
    private ?string $code = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?float $pourcentageCommissionSpecifiqueHT = null;

    #[ORM\Column]
    private ?int $branche = null;
    public const BRANCHE_IARD_OU_NON_VIE = 0;
    public const BRANCHE_VIE = 1;

    #[ORM\ManyToOne(inversedBy: 'risques')]
    private ?Entreprise $entreprise = null;

    #[ORM\Column(length: 255)]
    private ?string $nomComplet = null;

    #[ORM\Column]
    private ?bool $imposable = null;

    /**
     * @var Collection<int, Piste>
     */
    #[ORM\OneToMany(targetEntity: Piste::class, mappedBy: 'risque', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $pistes;

    /**
     * @var Collection<int, NotificationSinistre>
     */
    #[ORM\OneToMany(targetEntity: NotificationSinistre::class, mappedBy: 'risque')]
    private Collection $notificationSinistres;

    #[ORM\ManyToOne(inversedBy: 'produits')]
    private ?ConditionPartage $conditionPartage = null;


    public function __construct()
    {
        $this->pistes = new ArrayCollection();
        $this->notificationSinistres = new ArrayCollection();
    }

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPourcentageCommissionSpecifiqueHT(): ?float
    {
        return $this->pourcentageCommissionSpecifiqueHT;
    }

    public function setPourcentageCommissionSpecifiqueHT(?float $pourcentageCommissionSpecifiqueHT): static
    {
        $this->pourcentageCommissionSpecifiqueHT = $pourcentageCommissionSpecifiqueHT;

        return $this;
    }

    public function getBranche(): ?int
    {
        return $this->branche;
    }

    public function setBranche(int $branche): static
    {
        $this->branche = $branche;

        return $this;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): static
    {
        $this->entreprise = $entreprise;

        return $this;
    }

    public function getNomComplet(): ?string
    {
        return $this->nomComplet;
    }

    public function setNomComplet(string $nomComplet): static
    {
        $this->nomComplet = $nomComplet;

        return $this;
    }

    public function isImposable(): ?bool
    {
        return $this->imposable;
    }

    public function setImposable(bool $imposable): static
    {
        $this->imposable = $imposable;

        return $this;
    }

    /**
     * @return Collection<int, Piste>
     */
    public function getPistes(): Collection
    {
        return $this->pistes;
    }

    public function addPiste(Piste $piste): static
    {
        if (!$this->pistes->contains($piste)) {
            $this->pistes->add($piste);
            $piste->setRisque($this);
        }

        return $this;
    }

    public function removePiste(Piste $piste): static
    {
        if ($this->pistes->removeElement($piste)) {
            // set the owning side to null (unless already changed)
            if ($piste->getRisque() === $this) {
                $piste->setRisque(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->nomComplet;
    }

    /**
     * @return Collection<int, NotificationSinistre>
     */
    public function getNotificationSinistres(): Collection
    {
        return $this->notificationSinistres;
    }

    public function addNotificationSinistre(NotificationSinistre $notificationSinistre): static
    {
        if (!$this->notificationSinistres->contains($notificationSinistre)) {
            $this->notificationSinistres->add($notificationSinistre);
            $notificationSinistre->setRisque($this);
        }

        return $this;
    }

    public function removeNotificationSinistre(NotificationSinistre $notificationSinistre): static
    {
        if ($this->notificationSinistres->removeElement($notificationSinistre)) {
            // set the owning side to null (unless already changed)
            if ($notificationSinistre->getRisque() === $this) {
                $notificationSinistre->setRisque(null);
            }
        }

        return $this;
    }

    public function getConditionPartage(): ?ConditionPartage
    {
        return $this->conditionPartage;
    }

    public function setConditionPartage(?ConditionPartage $conditionPartage): static
    {
        $this->conditionPartage = $conditionPartage;

        return $this;
    }
}
