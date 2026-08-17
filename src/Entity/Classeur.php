<?php

namespace App\Entity;

use App\Repository\ClasseurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use App\Entity\Traits\AuditableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: ClasseurRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Classeur
{
    use AuditableTrait;
    public const NOM_CLASSEUR_POP = "PREUVES DES PAIEMENTS";
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
     * LE CLIENT DONT CE CLASSEUR EST LE CLASSEUR — null pour un classeur libre.
     *
     * POURQUOI CE LIEN EXISTE. Le classeur était un champ facultatif d'un formulaire, que
     * rien ne remplissait jamais : aucun code de production ne créait de classeur et
     * aucun ne posait `Document.classeur`. Résultat, tous les documents étaient « non
     * classés », et le rangement ne servait à rien. La règle est désormais qu'un client a
     * SON classeur, où atterrit automatiquement tout document de son dossier.
     *
     * POURQUOI UNE RELATION ET NON UNE CORRESPONDANCE DE NOM. Retrouver « le classeur du
     * client Kibali » par son intitulé casse au premier renommage du client, et se trompe
     * dès que deux clients portent le même nom — ce qui arrive. Le lien est donc explicite,
     * et c'est lui qui rend le rangement automatique IDEMPOTENT : on retrouve toujours le
     * même classeur, jamais un second à côté du premier.
     *
     * UNIQUE, ET NULLABLE. Unique parce qu'« un client, un classeur » est la règle : sans
     * cette contrainte, deux exécutions concurrentes en créeraient deux et le rangement
     * cesserait d'être déterministe. Nullable parce que les classeurs créés à la main
     * n'appartiennent à personne et doivent continuer d'exister tels quels — MySQL admet
     * autant de NULL qu'on veut dans un index unique, la contrainte ne les gêne pas.
     */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, unique: true, onDelete: 'CASCADE')]
    private ?Client $client = null;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'classeur')]
    private Collection $documents;

    //Attributs calculés
    #[Groups(['list:read'])]
    public ?int $nombreDocuments = null;

    #[Groups(['list:read'])]
    public ?string $ageClasseur = null;

    #[Groups(['list:read'])]
    public ?\DateTimeInterface $dateDernierAjout = null;

    #[Groups(['list:read'])]
    public ?array $apercuTypesFichiers = null;

    #[Groups(['list:read'])]
    public ?string $estVide = null;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
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

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Ce classeur est-il le classeur automatique d'un client ?
     *
     * Sert à le protéger de ce qu'on ferait sans y penser à un classeur libre : le
     * renommer, ou le vider de sa raison d'être.
     */
    public function estClasseurDeClient(): bool
    {
        return $this->client !== null;
    }

    public function __toString(): string
    {
        return $this->nom;
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
            $document->setClasseur($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getClasseur() === $this) {
                $document->setClasseur(null);
            }
        }

        return $this;
    }
}
