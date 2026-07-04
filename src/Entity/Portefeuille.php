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
    #[ORM\ManyToOne]
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

    // Attribut calculé
    #[Groups(['list:read'])]
    public ?int $nombreClients = null;

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
