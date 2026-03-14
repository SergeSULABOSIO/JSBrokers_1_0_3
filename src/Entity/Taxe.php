<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\TaxeRepository;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entité Taxe
 * Refactorisée : la relation avec Article a été supprimée.
 */
#[ORM\Entity(repositoryClass: TaxeRepository::class)]
class Taxe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

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

    /**
     * @var Collection<int, AutoriteFiscale>
     */
    #[ORM\OneToMany(targetEntity: AutoriteFiscale::class, mappedBy: 'taxe')]
    private Collection $autoriteFiscales;

    public function __construct()
    {
        $this->autoriteFiscales = new ArrayCollection();
        // L'initialisation de $this->articles a été supprimée.
    }

    public function getId(): ?int
    {
        return $this->id;
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
            // set the owning side to null (unless already changed)
            if ($autoriteFiscale->getTaxe() === $this) {
                $autoriteFiscale->setTaxe(null);
            }
        }

        return $this;
    }
}