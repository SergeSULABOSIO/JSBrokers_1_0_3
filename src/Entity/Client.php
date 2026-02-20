<?php

namespace App\Entity;

use Symfony\Component\Serializer\Annotation\Groups;
use App\Repository\ClientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;


#[ORM\Entity(repositoryClass: ClientRepository::class)]
class Client
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[Assert\NotBlank()]
    #[ORM\Column(length: 255)]
    #[Groups(['list:read'])]
    private ?string $nom = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $adresse = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $telephone = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $email = null;

    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?bool $exonere = null;

    #[ORM\ManyToOne(inversedBy: 'clients')]
    #[Groups(['list:read'])]
    private ?Entreprise $entreprise = null;

    /**
     * @var Collection<int, Contact>
     */
    #[ORM\OneToMany(targetEntity: Contact::class, mappedBy: 'client', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $contacts;

    /**
     * @var Collection<int, Piste>
     */
    #[ORM\OneToMany(targetEntity: Piste::class, mappedBy: 'client')]
    // #[Groups(['list:read'])]
    private Collection $pistes;

    /**
     * @var Collection<int, NotificationSinistre>
     */
    #[ORM\OneToMany(targetEntity: NotificationSinistre::class, mappedBy: 'assure')]
    // #[Groups(['list:read'])] // POUR NE TOMBER DANS LA BOUCLE INFINIE QUAND IL FERA LA SERIALISATION DES ENTITES
    private Collection $notificationSinistres;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'client', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    #[ORM\ManyToOne(inversedBy: 'clients')]
    #[Groups(['list:read'])]
    private ?Groupe $groupe = null;

    /**
     * @var Collection<int, Note>
     */
    #[ORM\OneToMany(targetEntity: Note::class, mappedBy: 'client')]
    private Collection $notes;

    /**
     * @var Collection<int, Partenaire>
     */
    #[ORM\ManyToMany(targetEntity: Partenaire::class, inversedBy: 'clients')]
    private Collection $partenaires;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $numimpot = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $rccm = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $idnat = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['list:read'])]
    private ?int $civilite = null;
    //Les civilités possible
    public const CIVILITE_Mr = 0;
    public const CIVILITE_Mme = 1;
    public const CIVILITE_ENTREPRISE = 2;
    public const CIVILITE_ASBL = 3;

    // Attributs calculés
    #[Groups(['list:read'])]
    public ?string $civiliteString = null;

    #[Groups(['list:read'])]
    public ?int $nombrePistes = null;

    #[Groups(['list:read'])]
    public ?int $nombreSinistres = null;

    #[Groups(['list:read'])]
    public ?int $nombrePolices = null;

    // NOUVEAU : Attributs financiers calculés
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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __construct()
    {
        $this->contacts = new ArrayCollection();
        $this->pistes = new ArrayCollection();
        $this->notificationSinistres = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->notes = new ArrayCollection();
        $this->partenaires = new ArrayCollection();
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

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
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

    public function isExonere(): ?bool
    {
        return $this->exonere;
    }

    public function setExonere(bool $exonere): static
    {
        $this->exonere = $exonere;

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
            $contact->setClient($this);
        }

        return $this;
    }

    public function removeContact(Contact $contact): static
    {
        if ($this->contacts->removeElement($contact)) {
            // set the owning side to null (unless already changed)
            if ($contact->getClient() === $this) {
                $contact->setClient(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom;
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
            $piste->setClient($this);
        }

        return $this;
    }

    public function removePiste(Piste $piste): static
    {
        if ($this->pistes->removeElement($piste)) {
            // set the owning side to null (unless already changed)
            if ($piste->getClient() === $this) {
                $piste->setClient(null);
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
            $notificationSinistre->setAssure($this);
        }

        return $this;
    }

    public function removeNotificationSinistre(NotificationSinistre $notificationSinistre): static
    {
        if ($this->notificationSinistres->removeElement($notificationSinistre)) {
            // set the owning side to null (unless already changed)
            if ($notificationSinistre->getAssure() === $this) {
                $notificationSinistre->setAssure(null);
            }
        }

        return $this;
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
            $document->setClient($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            // set the owning side to null (unless already changed)
            if ($document->getClient() === $this) {
                $document->setClient(null);
            }
        }

        return $this;
    }

    public function getGroupe(): ?Groupe
    {
        return $this->groupe;
    }

    public function setGroupe(?Groupe $groupe): static
    {
        $this->groupe = $groupe;

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
            $note->setClient($this);
        }

        return $this;
    }

    public function removeNote(Note $note): static
    {
        if ($this->notes->removeElement($note)) {
            // set the owning side to null (unless already changed)
            if ($note->getClient() === $this) {
                $note->setClient(null);
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
        }

        return $this;
    }

    public function removePartenaire(Partenaire $partenaire): static
    {
        $this->partenaires->removeElement($partenaire);

        return $this;
    }

    public function getNumimpot(): ?string
    {
        return $this->numimpot;
    }

    public function setNumimpot(?string $numimpot): static
    {
        $this->numimpot = $numimpot;

        return $this;
    }

    public function getRccm(): ?string
    {
        return $this->rccm;
    }

    public function setRccm(?string $rccm): static
    {
        $this->rccm = $rccm;

        return $this;
    }

    public function getIdnat(): ?string
    {
        return $this->idnat;
    }

    public function setIdnat(?string $idnat): static
    {
        $this->idnat = $idnat;

        return $this;
    }

    public function getCivilite(): ?int
    {
        return $this->civilite;
    }

    public function setCivilite(?int $civilite): static
    {
        $this->civilite = $civilite;

        return $this;
    }
}
