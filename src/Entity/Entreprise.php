<?php

namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Traits\TimestampableTrait;
use App\Repository\EntrepriseRepository;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\HttpFoundation\File\File;
use App\Entity\Traits\CalculatedIndicatorsTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EntrepriseRepository::class)]
#[Vich\Uploadable()]
#[ORM\HasLifecycleCallbacks]
class Entreprise
{
    use CalculatedIndicatorsTrait;
    use TimestampableTrait;
    
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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $thumbnail = null;

    // #[Vich\Uploadable(mapping:"profiles", fileNameProperty: "thumbnail")]
    #[Vich\UploadableField(mapping: 'entreprises', fileNameProperty: 'thumbnail')]
    #[Assert\Image()]
    private ?File $thumbnailFile = null;

    /**
     * @var Collection<int, Utilisateur>
     */
    #[ORM\OneToMany(targetEntity: Utilisateur::class, mappedBy: 'connectedTo')]
    private Collection $connectedUsers;

    /**
     * @var Collection<int, Invite>
     */
    #[ORM\OneToMany(targetEntity: Invite::class, mappedBy: 'entreprise', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $invites;

    #[ORM\Column(nullable: true)]
    private ?float $capitalSociale = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteweb = null;

    #[ORM\ManyToOne(cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $utilisateur = null;

    public function __construct()
    {
        $this->connectedUsers = new ArrayCollection();
        $this->invites = new ArrayCollection();
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
            if ($invite->getUtilisateur() && $invite->getUtilisateur()->getEmail() == $user->getEmail()) {
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

    public function setThumbnailFile(File $thumbnailFile): self
    {
        $this->thumbnailFile = $thumbnailFile;

        return $this;
    }

    public function getThumbnailFile(): ?File
    {
        return $this->thumbnailFile;
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

    public function getThumbnail(): ?string
    {
        return $this->thumbnail;
    }

    public function setThumbnail(?string $thumbnail): static
    {
        $this->thumbnail = $thumbnail;

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
            $invite->setEntreprise($this);
        }

        return $this;
    }

    public function removeInvite(Invite $invite): static
    {
        if ($this->invites->removeElement($invite)) {
            // set the owning side to null (unless already changed)
            if ($invite->getEntreprise() === $this) {
                $invite->setEntreprise(null);
            }
        }

        return $this;
    }

    public function getCapitalSociale(): ?float
    {
        return $this->capitalSociale;
    }

    public function setCapitalSociale(?float $capitalSociale): static
    {
        $this->capitalSociale = $capitalSociale;

        return $this;
    }

    public function getSiteweb(): ?string
    {
        return $this->siteweb;
    }

    public function setSiteweb(?string $siteweb): static
    {
        $this->siteweb = $siteweb;

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
}
