<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\InviteRepository;
use Doctrine\Common\Collections\Collection;
use App\Entity\Traits\CalculatedIndicatorsTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InviteRepository::class)]
class Invite implements OwnerAwareInterface
{
    use CalculatedIndicatorsTrait;

    public const ACCESS_LECTURE = 0;
    public const ACCESS_ECRITURE = 1;
    public const ACCESS_MODIFICATION = 2;
    public const ACCESS_SUPPRESSION = 3;


    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[Assert\NotBlank()]
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $email = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, Piste>
     */
    #[ORM\OneToMany(targetEntity: Piste::class, mappedBy: 'invite')]
    private Collection $pistes;

    /**
     * @var Collection<int, Tache>
     */
    #[ORM\OneToMany(targetEntity: Tache::class, mappedBy: 'executor')]
    private Collection $taches;

    /**
     * @var Collection<int, Feedback>
     */
    #[ORM\OneToMany(targetEntity: Feedback::class, mappedBy: 'invite')]
    private Collection $feedback;

    /**
     * @var Collection<int, Bordereau>
     */
    #[ORM\OneToMany(targetEntity: Bordereau::class, mappedBy: 'invite')]
    private Collection $bordereaus;

    /**
     * @var Collection<int, PieceSinistre>
     */
    #[ORM\OneToMany(targetEntity: PieceSinistre::class, mappedBy: 'invite')]
    private Collection $pieceSinistres;

    /**
     * @var Collection<int, NotificationSinistre>
     */
    #[ORM\OneToMany(targetEntity: NotificationSinistre::class, mappedBy: 'invite')]
    private Collection $notificationSinistres;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nom = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'assistants')]
    private ?self $invite = null;

    /**
     * @var Collection<int, self>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'invite')]
    private Collection $assistants;

    #[ORM\ManyToOne(inversedBy: 'invites')]
    private ?Entreprise $entreprise = null;

    #[ORM\Column(nullable: true)]
    private ?bool $proprietaire = null;

    /**
     * @var Collection<int, Note>
     */
    #[ORM\OneToMany(targetEntity: Note::class, mappedBy: 'invite')]
    private Collection $notes;

    /**
     * @var Collection<int, RolesEnFinance>
     */
    #[ORM\OneToMany(targetEntity: RolesEnFinance::class, mappedBy: 'invite', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $rolesEnFinance;

    /**
     * @var Collection<int, RolesEnMarketing>
     */
    #[ORM\OneToMany(targetEntity: RolesEnMarketing::class, mappedBy: 'invite', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $rolesEnMarketing;

    /**
     * @var Collection<int, RolesEnProduction>
     */
    #[ORM\OneToMany(targetEntity: RolesEnProduction::class, mappedBy: 'invite', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $rolesEnProduction;

    /**
     * @var Collection<int, RolesEnSinistre>
     */
    #[ORM\OneToMany(targetEntity: RolesEnSinistre::class, mappedBy: 'invite', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $rolesEnSinistre;

    /**
     * @var Collection<int, RolesEnAdministration>
     */
    #[ORM\OneToMany(targetEntity: RolesEnAdministration::class, mappedBy: 'invite', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $rolesEnAdministration;

    // Attributs calculés
    #[Groups(['list:read'])]
    public ?string $ageInvitation = null;

    #[Groups(['list:read'])]
    public ?int $tachesEnCours = null;

    #[Groups(['list:read'])]
    public ?string $rolePrincipal = null;

    #[Groups(['list:read'])]
    public ?string $proprietaireString = null;

    #[Groups(['list:read'])]
    public ?string $status_string = null;


    public function __construct()
    {
        // $this->entreprises = new ArrayCollection();
        $this->pistes = new ArrayCollection();
        $this->taches = new ArrayCollection();
        $this->feedback = new ArrayCollection();
        $this->bordereaus = new ArrayCollection();
        $this->pieceSinistres = new ArrayCollection();
        $this->notificationSinistres = new ArrayCollection();
        $this->assistants = new ArrayCollection();
        $this->notes = new ArrayCollection();
        $this->rolesEnFinance = new ArrayCollection();
        $this->rolesEnMarketing = new ArrayCollection();
        $this->rolesEnProduction = new ArrayCollection();
        $this->rolesEnSinistre = new ArrayCollection();
        $this->rolesEnAdministration = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

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
            $piste->setInvite($this);
        }

        return $this;
    }

    public function removePiste(Piste $piste): static
    {
        if ($this->pistes->removeElement($piste)) {
            // set the owning side to null (unless already changed)
            if ($piste->getInvite() === $this) {
                $piste->setInvite(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom . " (" . $this->email . ")";
    }

    /**
     * @return Collection<int, Tache>
     */
    public function getTaches(): Collection
    {
        return $this->taches;
    }

    /**
     * @return Collection<int, Feedback>
     */
    public function getFeedback(): Collection
    {
        return $this->feedback;
    }

    public function addFeedback(Feedback $feedback): static
    {
        if (!$this->feedback->contains($feedback)) {
            $this->feedback->add($feedback);
            $feedback->setInvite($this);
        }

        return $this;
    }

    public function removeFeedback(Feedback $feedback): static
    {
        if ($this->feedback->removeElement($feedback)) {
            // set the owning side to null (unless already changed)
            if ($feedback->getInvite() === $this) {
                $feedback->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Bordereau>
     */
    public function getBordereaus(): Collection
    {
        return $this->bordereaus;
    }

    public function addBordereau(Bordereau $bordereau): static
    {
        if (!$this->bordereaus->contains($bordereau)) {
            $this->bordereaus->add($bordereau);
            $bordereau->setInvite($this);
        }

        return $this;
    }

    public function removeBordereau(Bordereau $bordereau): static
    {
        if ($this->bordereaus->removeElement($bordereau)) {
            // set the owning side to null (unless already changed)
            if ($bordereau->getInvite() === $this) {
                $bordereau->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PieceSinistre>
     */
    public function getPieceSinistres(): Collection
    {
        return $this->pieceSinistres;
    }

    public function addPieceSinistre(PieceSinistre $pieceSinistre): static
    {
        if (!$this->pieceSinistres->contains($pieceSinistre)) {
            $this->pieceSinistres->add($pieceSinistre);
            $pieceSinistre->setInvite($this);
        }

        return $this;
    }

    public function removePieceSinistre(PieceSinistre $pieceSinistre): static
    {
        if ($this->pieceSinistres->removeElement($pieceSinistre)) {
            // set the owning side to null (unless already changed)
            if ($pieceSinistre->getInvite() === $this) {
                $pieceSinistre->setInvite(null);
            }
        }

        return $this;
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
            $notificationSinistre->setInvite($this);
        }

        return $this;
    }

    public function removeNotificationSinistre(NotificationSinistre $notificationSinistre): static
    {
        if ($this->notificationSinistres->removeElement($notificationSinistre)) {
            // set the owning side to null (unless already changed)
            if ($notificationSinistre->getInvite() === $this) {
                $notificationSinistre->setInvite(null);
            }
        }

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getInvite(): ?self
    {
        return $this->invite;
    }

    public function setInvite(?self $invite): static
    {
        $this->invite = $invite;

        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getAssistants(): Collection
    {
        return $this->assistants;
    }

    public function addAssistant(self $assistant): static
    {
        if (!$this->assistants->contains($assistant)) {
            $this->assistants->add($assistant);
            $assistant->setInvite($this);
        }

        return $this;
    }

    public function removeAssistant(self $assistant): static
    {
        if ($this->assistants->removeElement($assistant)) {
            // set the owning side to null (unless already changed)
            if ($assistant->getInvite() === $this) {
                $assistant->setInvite(null);
            }
        }

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

    public function isProprietaire(): ?bool
    {
        return $this->proprietaire;
    }

    public function setProprietaire(?bool $proprietaire): static
    {
        $this->proprietaire = $proprietaire;

        return $this;
    }

    /**
     * @return Collection<int, Note>
     */
    public function getNotes(): Collection
    {
        return $this->notes;
    }

    public function addNote(Note $note): static
    {
        if (!$this->notes->contains($note)) {
            $this->notes->add($note);
            $note->setInvite($this);
        }

        return $this;
    }

    public function removeNote(Note $note): static
    {
        if ($this->notes->removeElement($note)) {
            // set the owning side to null (unless already changed)
            if ($note->getInvite() === $this) {
                $note->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, RolesEnFinance>
     */
    public function getRolesEnFinance(): Collection
    {
        return $this->rolesEnFinance;
    }

    public function addRolesEnFinance(RolesEnFinance $rolesEnFinance): static
    {
        if (!$this->rolesEnFinance->contains($rolesEnFinance)) {
            $this->rolesEnFinance->add($rolesEnFinance);
            $rolesEnFinance->setInvite($this);
        }

        return $this;
    }

    public function removeRolesEnFinance(RolesEnFinance $rolesEnFinance): static
    {
        if ($this->rolesEnFinance->removeElement($rolesEnFinance)) {
            // set the owning side to null (unless already changed)
            if ($rolesEnFinance->getInvite() === $this) {
                $rolesEnFinance->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, RolesEnMarketing>
     */
    public function getRolesEnMarketing(): Collection
    {
        return $this->rolesEnMarketing;
    }

    public function addRolesEnMarketing(RolesEnMarketing $rolesEnMarketing): static
    {
        if (!$this->rolesEnMarketing->contains($rolesEnMarketing)) {
            $this->rolesEnMarketing->add($rolesEnMarketing);
            $rolesEnMarketing->setInvite($this);
        }

        return $this;
    }

    public function removeRolesEnMarketing(RolesEnMarketing $rolesEnMarketing): static
    {
        if ($this->rolesEnMarketing->removeElement($rolesEnMarketing)) {
            // set the owning side to null (unless already changed)
            if ($rolesEnMarketing->getInvite() === $this) {
                $rolesEnMarketing->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, RolesEnProduction>
     */
    public function getRolesEnProduction(): Collection
    {
        return $this->rolesEnProduction;
    }

    public function addRolesEnProduction(RolesEnProduction $rolesEnProduction): static
    {
        if (!$this->rolesEnProduction->contains($rolesEnProduction)) {
            $this->rolesEnProduction->add($rolesEnProduction);
            $rolesEnProduction->setInvite($this);
        }

        return $this;
    }

    public function removeRolesEnProduction(RolesEnProduction $rolesEnProduction): static
    {
        if ($this->rolesEnProduction->removeElement($rolesEnProduction)) {
            // set the owning side to null (unless already changed)
            if ($rolesEnProduction->getInvite() === $this) {
                $rolesEnProduction->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, RolesEnSinistre>
     */
    public function getRolesEnSinistre(): Collection
    {
        return $this->rolesEnSinistre;
    }

    public function addRolesEnSinistre(RolesEnSinistre $rolesEnSinistre): static
    {
        if (!$this->rolesEnSinistre->contains($rolesEnSinistre)) {
            $this->rolesEnSinistre->add($rolesEnSinistre);
            $rolesEnSinistre->setInvite($this);
        }

        return $this;
    }

    public function removeRolesEnSinistre(RolesEnSinistre $rolesEnSinistre): static
    {
        if ($this->rolesEnSinistre->removeElement($rolesEnSinistre)) {
            // set the owning side to null (unless already changed)
            if ($rolesEnSinistre->getInvite() === $this) {
                $rolesEnSinistre->setInvite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, RolesEnAdministration>
     */
    public function getRolesEnAdministration(): Collection
    {
        return $this->rolesEnAdministration;
    }

    public function addRolesEnAdministration(RolesEnAdministration $rolesEnAdministration): static
    {
        if (!$this->rolesEnAdministration->contains($rolesEnAdministration)) {
            $this->rolesEnAdministration->add($rolesEnAdministration);
            $rolesEnAdministration->setInvite($this);
        }

        return $this;
    }

    public function removeRolesEnAdministration(RolesEnAdministration $rolesEnAdministration): static
    {
        if ($this->rolesEnAdministration->removeElement($rolesEnAdministration)) {
            // set the owning side to null (unless already changed)
            if ($rolesEnAdministration->getInvite() === $this) {
                $rolesEnAdministration->setInvite(null);
            }
        }

        return $this;
    }
}
