<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\GroupeRepository;
use Doctrine\Common\Collections\Collection;
use App\Entity\Traits\AuditableTrait;
use App\Entity\Traits\CalculatedIndicatorsTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * ⚠ UN GROUPE DE CLIENTS N'EXISTE QU'UNE FOIS PAR CABINET.
 *
 * Le catalogue en portait jusqu'à SIX exemplaires identiques : `ServiceInitialisation-
 * Entreprise` semait sans regarder si le poste existait, et `app:conges:provisionner`
 * rejouait ce semis en entier à chaque exécution. L'utilisateur voyait alors six lignes
 * qui se ressemblent, sans pouvoir savoir laquelle sa police employait ni laquelle
 * modifier le jour où le taux change.
 *
 * Le semis est devenu idempotent, mais une règle qui ne vit que dans du PHP est une règle
 * qu'un import, un script de reprise ou une seconde route peut contourner sans le savoir.
 * Elle est donc posée LÀ OÙ ELLE NE SE CONTOURNE PAS.
 *
 * ⚠ ET ELLE EST INSENSIBLE À LA CASSE, par la collation de la colonne — ce qui est voulu :
 * « Écart » et « écart » désignent le même poste, et deux lignes qui ne se distinguent que
 * par une majuscule sont un doublon pour tout le monde sauf pour la machine.
 */
#[ORM\Entity(repositoryClass: GroupeRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_groupe_entreprise_cle', columns: ['entreprise_id', 'nom'])]
class Groupe
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

    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $description = null;

    /**
     * @var Collection<int, Client>
     */
    #[ORM\OneToMany(targetEntity: Client::class, mappedBy: 'groupe', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $clients;

    // Attributs calculés
    #[Groups(['list:read'])]
    public ?int $nombreClients;

    #[Groups(['list:read'])]
    public ?int $nombrePolices;

    #[Groups(['list:read'])]
    public ?int $nombreSinistres;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->clients = new ArrayCollection();
    }

    /**
     * @var Collection<int, Document> Pièces jointes de cette fiche.
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'groupe', cascade: ['persist', 'remove'], orphanRemoval: true)]
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
            $document->setGroupe($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getGroupe() === $this) {
                $document->setGroupe(null);
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
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
            $client->setGroupe($this);
        }

        return $this;
    }

    public function removeClient(Client $client): static
    {
        if ($this->clients->removeElement($client)) {
            // set the owning side to null (unless already changed)
            if ($client->getGroupe() === $this) {
                $client->setGroupe(null);
            }
        }

        return $this;
    }
}
