<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\EntrepriseRepository;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\HttpFoundation\File\File;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: EntrepriseRepository::class)]
#[Vich\Uploadable()]
class Entreprise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotBlank()]
    #[ORM\Column(length: 255)]
    private ?string $nom = null;


    #[Assert\NotBlank()]
    #[ORM\Column(length: 255)]
    private ?string $licence = null;


    #[Assert\NotBlank()]
    #[ORM\Column(length: 255)]
    private ?string $adresse = null;

    #[Assert\NotBlank()]
    #[ORM\Column(length: 255)]
    private ?string $telephone = null;

    #[ORM\Column(length: 255)]
    private ?string $rccm = null;

    #[ORM\Column(length: 255)]
    private ?string $idnat = null;

    #[ORM\Column(length: 255)]
    private ?string $numimpot = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, Invite>
     */
    #[ORM\ManyToMany(targetEntity: Invite::class, mappedBy: 'entreprises')]
    private Collection $invites;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $thumbnail = null;

    // #[Vich\Uploadable(mapping:"profiles", fileNameProperty: "thumbnail")]
    #[Vich\UploadableField(mapping: 'entreprises', fileNameProperty: 'thumbnail')]
    #[Assert\Image()]
    private ?File $thumbnailFile = null;

    #[ORM\ManyToOne(inversedBy: 'entreprises')]
    private ?Utilisateur $utilisateur = null;

    /**
     * @var Collection<int, Monnaie>
     */
    #[ORM\OneToMany(targetEntity: Monnaie::class, mappedBy: 'entreprise', cascade:['persist', 'remove'], orphanRemoval:true)]
    #[Assert\Valid()]
    private Collection $monnaies;

    /**
     * @var Collection<int, Taxe>
     */
    #[ORM\OneToMany(targetEntity: Taxe::class, mappedBy: 'entreprise')]
    private Collection $taxes;

    /**
     * @var Collection<int, CompteBancaire>
     */
    #[ORM\OneToMany(targetEntity: CompteBancaire::class, mappedBy: 'entreprise')]
    private Collection $compteBancaires;

    /**
     * @var Collection<int, Revenu>
     */
    #[ORM\OneToMany(targetEntity: Revenu::class, mappedBy: 'entreprise')]
    private Collection $revenus;

    /**
     * @var Collection<int, Risque>
     */
    #[ORM\OneToMany(targetEntity: Risque::class, mappedBy: 'entreprise')]
    private Collection $risques;

    /**
     * @var Collection<int, Chargement>
     */
    #[ORM\OneToMany(targetEntity: Chargement::class, mappedBy: 'entreprise')]
    private Collection $chargements;

    /**
     * @var Collection<int, Classeur>
     */
    #[ORM\OneToMany(targetEntity: Classeur::class, mappedBy: 'entreprise')]
    private Collection $classeurs;

    /**
     * @var Collection<int, Assureur>
     */
    #[ORM\OneToMany(targetEntity: Assureur::class, mappedBy: 'entreprise')]
    private Collection $assureurs;

    /**
     * @var Collection<int, Client>
     */
    #[ORM\OneToMany(targetEntity: Client::class, mappedBy: 'entreprise')]
    private Collection $clients;

    /**
     * @var Collection<int, Partenaire>
     */
    #[ORM\OneToMany(targetEntity: Partenaire::class, mappedBy: 'entreprise')]
    private Collection $partenaires;

    /**
     * @var Collection<int, Contact>
     */
    #[ORM\OneToMany(targetEntity: Contact::class, mappedBy: 'entreprise')]
    private Collection $contacts;

    /**
     * @var Collection<int, Utilisateur>
     */
    #[ORM\OneToMany(targetEntity: Utilisateur::class, mappedBy: 'connectedTo')]
    private Collection $connectedUsers;

    /**
     * @var Collection<int, ModelePieceSinistre>
     */
    #[ORM\OneToMany(targetEntity: ModelePieceSinistre::class, mappedBy: 'entreprise')]
    private Collection $modelePieceSinistres;

    public function __construct()
    {
        $this->invites = new ArrayCollection();
        $this->monnaies = new ArrayCollection();
        $this->taxes = new ArrayCollection();
        $this->compteBancaires = new ArrayCollection();
        $this->revenus = new ArrayCollection();
        $this->risques = new ArrayCollection();
        $this->chargements = new ArrayCollection();
        $this->classeurs = new ArrayCollection();
        $this->assureurs = new ArrayCollection();
        $this->clients = new ArrayCollection();
        $this->partenaires = new ArrayCollection();
        $this->contacts = new ArrayCollection();
        $this->connectedUsers = new ArrayCollection();
        $this->modelePieceSinistres = new ArrayCollection();
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): self
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function isInvited(?Utilisateur $user): bool
    {
        foreach ($this->getInvites() as $invite) {
            if($invite->getEmail() == $user->getEmail()){
                return true;
            }
        }
        return false;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): self
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getRccm(): ?string
    {
        return $this->rccm;
    }

    public function setRccm(?string $rccm): self
    {
        $this->rccm = $rccm;

        return $this;
    }

    public function getIdnat(): ?string
    {
        return $this->idnat;
    }

    public function setIdnat(?string $idnat): self
    {
        $this->idnat = $idnat;

        return $this;
    }

    public function getNumimpot(): ?string
    {
        return $this->numimpot;
    }

    public function setNumimpot(?string $numimpot): self
    {
        $this->numimpot = $numimpot;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getThumbnailFile(): ?File
    {
        return $this->thumbnailFile;
    }

    public function setThumbnailFile(File $thumbnailFile): self
    {
        $this->thumbnailFile = $thumbnailFile;

        return $this;
    }


    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * Get the value of licence
     */
    public function getLicence()
    {
        return $this->licence;
    }

    /**
     * Set the value of licence
     *
     * @return  self
     */
    public function setLicence($licence)
    {
        $this->licence = $licence;

        return $this;
    }

    /**
     * @return Collection<int, Invite>
     */
    public function getInvites(): Collection
    {
        return $this->invites;
    }

    public function addInvite(Invite $invite): static
    {
        if (!$this->invites->contains($invite)) {
            $this->invites->add($invite);
            $invite->addEntreprise($this);
        }

        return $this;
    }

    public function removeInvite(Invite $invite): static
    {
        if ($this->invites->removeElement($invite)) {
            $invite->removeEntreprise($this);
        }

        return $this;
    }

    public function getThumbnail(): ?string
    {
        return $this->thumbnail;
    }

    public function setThumbnail(?string $thumbnail): static
    {
        $this->thumbnail = $thumbnail;

        return $this;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    /**
     * @return Collection<int, Monnaie>
     */
    public function getMonnaies(): Collection
    {
        return $this->monnaies;
    }

    public function addMonnaie(Monnaie $monnaie): static
    {
        if (!$this->monnaies->contains($monnaie)) {
            $this->monnaies->add($monnaie);
            $monnaie->setEntreprise($this);
        }

        return $this;
    }

    public function removeMonnaie(Monnaie $monnaie): static
    {
        if ($this->monnaies->removeElement($monnaie)) {
            // set the owning side to null (unless already changed)
            if ($monnaie->getEntreprise() === $this) {
                $monnaie->setEntreprise(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Taxe>
     */
    public function getTaxes(): Collection
    {
        return $this->taxes;
    }

    public function addTax(Taxe $tax): static
    {
        if (!$this->taxes->contains($tax)) {
            $this->taxes->add($tax);
            $tax->setEntreprise($this);
        }

        return $this;
    }

    public function removeTax(Taxe $tax): static
    {
        if ($this->taxes->removeElement($tax)) {
            // set the owning side to null (unless already changed)
            if ($tax->getEntreprise() === $this) {
                $tax->setEntreprise(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, CompteBancaire>
     */
    public function getCompteBancaires(): Collection
    {
        return $this->compteBancaires;
    }

    public function addCompteBancaire(CompteBancaire $compteBancaire): static
    {
        if (!$this->compteBancaires->contains($compteBancaire)) {
            $this->compteBancaires->add($compteBancaire);
            $compteBancaire->setEntreprise($this);
        }

        return $this;
    }

    public function removeCompteBancaire(CompteBancaire $compteBancaire): static
    {
        if ($this->compteBancaires->removeElement($compteBancaire)) {
            // set the owning side to null (unless already changed)
            if ($compteBancaire->getEntreprise() === $this) {
                $compteBancaire->setEntreprise(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Revenu>
     */
    public function getRevenus(): Collection
    {
        return $this->revenus;
    }

    public function addRevenu(Revenu $revenu): static
    {
        if (!$this->revenus->contains($revenu)) {
            $this->revenus->add($revenu);
            $revenu->setEntreprise($this);
        }

        return $this;
    }

    public function removeRevenu(Revenu $revenu): static
    {
        if ($this->revenus->removeElement($revenu)) {
            // set the owning side to null (unless already changed)
            if ($revenu->getEntreprise() === $this) {
                $revenu->setEntreprise(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Risque>
     */
    public function getRisques(): Collection
    {
        return $this->risques;
    }

    public function addRisque(Risque $risque): static
    {
        if (!$this->risques->contains($risque)) {
            $this->risques->add($risque);
            $risque->setEntreprise($this);
        }

        return $this;
    }

    public function removeRisque(Risque $risque): static
    {
        if ($this->risques->removeElement($risque)) {
            // set the owning side to null (unless already changed)
            if ($risque->getEntreprise() === $this) {
                $risque->setEntreprise(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Chargement>
     */
    public function getChargements(): Collection
    {
        return $this->chargements;
    }

    public function addChargement(Chargement $chargement): static
    {
        if (!$this->chargements->contains($chargement)) {
            $this->chargements->add($chargement);
            $chargement->setEntreprise($this);
        }

        return $this;
    }

    public function removeChargement(Chargement $chargement): static
    {
        if ($this->chargements->removeElement($chargement)) {
            // set the owning side to null (unless already changed)
            if ($chargement->getEntreprise() === $this) {
                $chargement->setEntreprise(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Classeur>
     */
    public function getClasseurs(): Collection
    {
        return $this->classeurs;
    }

    public function addClasseur(Classeur $classeur): static
    {
        if (!$this->classeurs->contains($classeur)) {
            $this->classeurs->add($classeur);
            $classeur->setEntreprise($this);
        }

        return $this;
    }

    public function removeClasseur(Classeur $classeur): static
    {
        if ($this->classeurs->removeElement($classeur)) {
            // set the owning side to null (unless already changed)
            if ($classeur->getEntreprise() === $this) {
                $classeur->setEntreprise(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Assureur>
     */
    public function getAssureurs(): Collection
    {
        return $this->assureurs;
    }

    public function addAssureur(Assureur $assureur): static
    {
        if (!$this->assureurs->contains($assureur)) {
            $this->assureurs->add($assureur);
            $assureur->setEntreprise($this);
        }

        return $this;
    }

    public function removeAssureur(Assureur $assureur): static
    {
        if ($this->assureurs->removeElement($assureur)) {
            // set the owning side to null (unless already changed)
            if ($assureur->getEntreprise() === $this) {
                $assureur->setEntreprise(null);
            }
        }

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
            $client->setEntreprise($this);
        }

        return $this;
    }

    public function removeClient(Client $client): static
    {
        if ($this->clients->removeElement($client)) {
            // set the owning side to null (unless already changed)
            if ($client->getEntreprise() === $this) {
                $client->setEntreprise(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Partenaire>
     */
    public function getPartenaires(): Collection
    {
        return $this->partenaires;
    }

    public function addPartenaire(Partenaire $partenaire): static
    {
        if (!$this->partenaires->contains($partenaire)) {
            $this->partenaires->add($partenaire);
            $partenaire->setEntreprise($this);
        }

        return $this;
    }

    public function removePartenaire(Partenaire $partenaire): static
    {
        if ($this->partenaires->removeElement($partenaire)) {
            // set the owning side to null (unless already changed)
            if ($partenaire->getEntreprise() === $this) {
                $partenaire->setEntreprise(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Contact>
     */
    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    public function addContact(Contact $contact): static
    {
        if (!$this->contacts->contains($contact)) {
            $this->contacts->add($contact);
            $contact->setEntreprise($this);
        }

        return $this;
    }

    public function removeContact(Contact $contact): static
    {
        if ($this->contacts->removeElement($contact)) {
            // set the owning side to null (unless already changed)
            if ($contact->getEntreprise() === $this) {
                $contact->setEntreprise(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Utilisateur>
     */
    public function getConnectedUsers(): Collection
    {
        return $this->connectedUsers;
    }

    public function addConnectedUser(Utilisateur $connectedUser): static
    {
        if (!$this->connectedUsers->contains($connectedUser)) {
            $this->connectedUsers->add($connectedUser);
            $connectedUser->setConnectedTo($this);
        }

        return $this;
    }

    public function removeConnectedUser(Utilisateur $connectedUser): static
    {
        if ($this->connectedUsers->removeElement($connectedUser)) {
            // set the owning side to null (unless already changed)
            if ($connectedUser->getConnectedTo() === $this) {
                $connectedUser->setConnectedTo(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ModelePieceSinistre>
     */
    public function getModelePieceSinistres(): Collection
    {
        return $this->modelePieceSinistres;
    }

    public function addModelePieceSinistre(ModelePieceSinistre $modelePieceSinistre): static
    {
        if (!$this->modelePieceSinistres->contains($modelePieceSinistre)) {
            $this->modelePieceSinistres->add($modelePieceSinistre);
            $modelePieceSinistre->setEntreprise($this);
        }

        return $this;
    }

    public function removeModelePieceSinistre(ModelePieceSinistre $modelePieceSinistre): static
    {
        if ($this->modelePieceSinistres->removeElement($modelePieceSinistre)) {
            // set the owning side to null (unless already changed)
            if ($modelePieceSinistre->getEntreprise() === $this) {
                $modelePieceSinistre->setEntreprise(null);
            }
        }

        return $this;
    }
}
