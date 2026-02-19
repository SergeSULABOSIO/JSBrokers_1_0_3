<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\ContactRepository;
use App\Entity\Traits\CalculatedIndicatorsTrait;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: ContactRepository::class)]
class Contact
{
    use CalculatedIndicatorsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $telephone = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $fonction = null;

    #[ORM\ManyToOne(inversedBy: 'contacts')]
    // #[Groups(['list:read'])]
    private ?Client $client = null;

    #[ORM\ManyToOne(inversedBy: 'contacts')]
    // #[Groups(['list:read'])]
    private ?NotificationSinistre $notificationSinistre = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $type = null;
    public const TYPE_CONTACT_PRODUCTION = 0;
    public const TYPE_CONTACT_SINISTRE = 1;
    public const TYPE_CONTACT_ADMINISTRATION = 2;
    public const TYPE_CONTACT_AUTRES = 3;

    //Attributs calculés
    #[Groups(['list:read'])]
    public ?string $type_string;

    // NOUVEAU : Attributs calculés financiers et de sinistralité (Miroir de Partenaire/Risque)
    #[Groups(['list:read'])]
    public ?float $primeTotale = null;

    #[Groups(['list:read'])]
    public ?float $primePayee = null;

    #[Groups(['list:read'])]
    public ?float $primeSoldeDue = null;

    #[Groups(['list:read'])]
    public ?float $tauxCommission = null;

    #[Groups(['list:read'])]
    public ?float $montantHT = null;

    #[Groups(['list:read'])]
    public ?float $montantTTC = null;

    #[Groups(['list:read'])]
    public ?string $detailCalcul = null;

    #[Groups(['list:read'])]
    public ?float $taxeCourtierMontant = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurMontant = null;

    #[Groups(['list:read'])]
    public ?float $montant_du = null;

    #[Groups(['list:read'])]
    public ?float $montant_paye = null;

    #[Groups(['list:read'])]
    public ?float $solde_restant_du = null;

    #[Groups(['list:read'])]
    public ?float $montantPur = null;

    #[Groups(['list:read'])]
    public ?float $reserve = null;

    #[Groups(['list:read'])]
    public ?float $indemnisationDue = null;

    #[Groups(['list:read'])]
    public ?float $indemnisationVersee = null;

    #[Groups(['list:read'])]
    public ?float $indemnisationSolde = null;

    #[Groups(['list:read'])]
    public ?float $tauxSP = null;

    #[Groups(['list:read'])]
    public ?string $tauxSPInterpretation = null;


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

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getFonction(): ?string
    {
        return $this->fonction;
    }

    public function setFonction(?string $fonction): static
    {
        $this->fonction = $fonction;

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

    public function __toString(): string
    {
        return $this->nom;
    }

    public function getNotificationSinistre(): ?NotificationSinistre
    {
        return $this->notificationSinistre;
    }

    public function setNotificationSinistre(?NotificationSinistre $notificationSinistre): static
    {
        $this->notificationSinistre = $notificationSinistre;

        return $this;
    }

    public function getType(): ?int
    {
        return $this->type;
    }

    public function setType(int $type): static
    {
        $this->type = $type;

        return $this;
    }
}
