<?php

namespace App\Entity;

use App\Entity\Entreprise;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\TaxeRepository;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TaxeRepository::class)]
class Taxe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[Assert\NotBlank(message: "Le code ne peut pas être vide.")]
    #[ORM\Column(length: 50)]
    #[Groups(['list:read'])]
    private ?string $code = null;

    #[Assert\NotBlank(message: "Ce champ ne peut pas être vide.")]
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    #[Assert\NotBlank(message: "Ce champ ne peut pas être vide.")]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Groups(['list:read'])]
    private ?string $tauxIARD = null;

    #[Assert\NotBlank(message: "Ce champ ne peut pas être vide.")]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Groups(['list:read'])]
    private ?string $tauxVIE = null;

    #[ORM\ManyToOne(inversedBy: 'taxes')]
    #[Groups(['list:read'])]
    private ?Entreprise $entreprise = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?int $redevable = null;

    public const REDEVABLE_COURTIER = 0;
    public const REDEVABLE_ASSUREUR = 1;

    /**
     * @var Collection<int, AutoriteFiscale>
     */
    #[ORM\OneToMany(targetEntity: AutoriteFiscale::class, mappedBy: 'taxe')]
    private Collection $autoriteFiscales;

    public function __construct()
    {
        $this->autoriteFiscales = new ArrayCollection();
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

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getTauxIARD(): ?string
    {
        return $this->tauxIARD;
    }

    public function setTauxIARD(string $tauxIARD): static
    {
        $this->tauxIARD = $tauxIARD;
        return $this;
    }

    public function getTauxVIE(): ?string
    {
        return $this->tauxVIE;
    }

    public function setTauxVIE(string $tauxVIE): static
    {
        $this->tauxVIE = $tauxVIE;
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

    public function getRedevable(): ?int
    {
        return $this->redevable;
    }

    public function setRedevable(?int $redevable): static
    {
        $this->redevable = $redevable;
        return $this;
    }

    /**
     * @return Collection<int, AutoriteFiscale>
     */
    public function getAutoriteFiscales(): Collection
    {
        return $this->autoriteFiscales;
    }

    public function addAutoriteFiscale(AutoriteFiscale $autoriteFiscale): static
    {
        if (!$this->autoriteFiscales->contains($autoriteFiscale)) {
            $this->autoriteFiscales->add($autoriteFiscale);
            $autoriteFiscale->setTaxe($this);
        }
        return $this;
    }

    public function removeAutoriteFiscale(AutoriteFiscale $autoriteFiscale): static
    {
        if ($this->autoriteFiscales->removeElement($autoriteFiscale)) {
            if ($autoriteFiscale->getTaxe() === $this) {
                $autoriteFiscale->setTaxe(null);
            }
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->code ?? 'Taxe sans code';
    }
}