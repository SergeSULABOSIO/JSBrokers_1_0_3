<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\PortefeuilleRepository;
use Doctrine\Common\Collections\Collection;
use App\Entity\Traits\AuditableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PortefeuilleRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Portefeuille
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[Assert\NotBlank()]
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    /**
     * L'invité désigné comme gestionnaire de compte responsable du portefeuille.
     */
    #[ORM\ManyToOne(inversedBy: 'portefeuilles')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['list:read'])]
    private ?Invite $gestionnaire = null;

    /**
     * @var Collection<int, Client>
     *
     * Pas de cascade remove / orphanRemoval : supprimer un portefeuille ne supprime
     * pas les clients, il les détache seulement (client.portefeuille remis à null).
     */
    #[ORM\OneToMany(targetEntity: Client::class, mappedBy: 'portefeuille')]
    private Collection $clients;

    // Attributs calculés (agrégés sur les clients du portefeuille) — MÊME jeu que l'entité
    // Client (cf. PortefeuilleIndicatorStrategy / ClientIndicatorStrategy).
    #[Groups(['list:read'])]
    public ?int $nombreClients = null;

    #[Groups(['list:read'])]
    public ?int $nombrePistes = null;

    #[Groups(['list:read'])]
    public ?int $nombrePolices = null;

    #[Groups(['list:read'])]
    public ?int $nombreSinistres = null;

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
    public ?float $taxeCourtierPayee = null;

    #[Groups(['list:read'])]
    public ?float $taxeCourtierSolde = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurPayee = null;

    #[Groups(['list:read'])]
    public ?float $taxeAssureurSolde = null;

    #[Groups(['list:read'])]
    public ?float $montantPur = null;

    #[Groups(['list:read'])]
    public ?float $retroCommission = null;

    #[Groups(['list:read'])]
    public ?float $retroCommissionReversee = null;

    #[Groups(['list:read'])]
    public ?float $retroCommissionSolde = null;

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

    #[Groups(['list:read'])]
    public ?float $indiceSolvabilite = null;

    #[Groups(['list:read'])]
    public ?string $indiceSolvabiliteInterpretation = null;

    public function __construct()
    {
        $this->clients = new ArrayCollection();
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

    public function getGestionnaire(): ?Invite
    {
        return $this->gestionnaire;
    }

    public function setGestionnaire(?Invite $gestionnaire): static
    {
        $this->gestionnaire = $gestionnaire;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom ?? '';
    }

    /**
     * @return Collection<int, Client>
     */
    public function getClients(): Collection
    {
        return $this->clients;
    }

    public function addClient(Client $client): static
    {
        if (!$this->clients->contains($client)) {
            $this->clients->add($client);
            $client->setPortefeuille($this);
        }

        return $this;
    }

    public function removeClient(Client $client): static
    {
        if ($this->clients->removeElement($client)) {
            // set the owning side to null (unless already changed)
            if ($client->getPortefeuille() === $this) {
                $client->setPortefeuille(null);
            }
        }

        return $this;
    }
}
