<?php

namespace App\Entity;

use App\Repository\AssistantDocumentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * DOCUMENT PRODUIT par l'assistant IA — le miroir de AssistantConversationFichier,
 * dans l'autre sens : celui-là, l'utilisateur le dépose ; celui-ci, Ket le fabrique.
 *
 * C'est la première fois qu'un fichier né du serveur est CONSERVÉ : les exports
 * (facture PDF, export de bulle, classeur comptable) sont tous rendus à la volée et
 * jamais stockés. Ici il faut le garder, pour une raison simple — il a été payé en
 * tokens. Le faire disparaître au premier rechargement de page reviendrait à faire
 * payer deux fois le même livrable.
 *
 * Le binaire est stocké HORS public/ (mapping Vich « assistant_documents » →
 * var/uploads/assistant-documents) et n'est servi que par une route de téléchargement
 * fail-closed scopée à la conversation, comme les pièces jointes.
 *
 * `message` est UNIQUE : c'est l'anti-rejeu au niveau de la base. La meta du message
 * porte déjà le drapeau `documentPlanExecuted`, mais un drapeau se contourne par une
 * course ; une contrainte d'unicité, non.
 */
#[ORM\Entity(repositoryClass: AssistantDocumentRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_assistant_document_message', columns: ['message_id'])]
#[Vich\Uploadable]
class AssistantDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'documentsGeneres')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AssistantConversation $conversation = null;

    /** Le message assistant qui a présenté le plan — porte l'unicité anti-rejeu. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AssistantMessage $message = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entreprise $entreprise = null;

    /** Qui a validé la production (l'acteur, pas le payeur : c'est le propriétaire qui paie). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $auteur = null;

    /** Titre du rapport — libellé du bouton de téléchargement. */
    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    /**
     * Nom de TÉLÉCHARGEMENT, assaini et figé à la production. Jamais recalculé au
     * moment du clic : ce que l'utilisateur a vu sur le bouton est ce qu'il obtient.
     */
    #[ORM\Column(length: 255)]
    private ?string $nomFichier = null;

    #[ORM\Column(length: 8)]
    private ?string $format = null;

    #[ORM\Column(length: 160)]
    private ?string $mime = null;

    /** Taille en octets — alimente l'affichage « 47,1 Ko » du bouton. */
    #[ORM\Column]
    private int $taille = 0;

    /** Traçabilité du barème appliqué (le tarif peut changer après coup). */
    #[ORM\Column]
    private int $caracteres = 0;

    #[ORM\Column]
    private int $pages = 0;

    /** Ce qui a été RÉELLEMENT débité, pas ce qui avait été estimé. */
    #[ORM\Column]
    private int $coutTokens = 0;

    /**
     * Binaire porté par Vich — NON persisté directement. Le déplacement physique a
     * lieu au flush ; le nom de stockage unique est écrit dans nomFichierStocke.
     */
    #[Vich\UploadableField(mapping: 'assistant_documents', fileNameProperty: 'nomFichierStocke')]
    private ?File $fichier = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomFichierStocke = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): ?AssistantConversation
    {
        return $this->conversation;
    }

    public function setConversation(?AssistantConversation $conversation): self
    {
        $this->conversation = $conversation;

        return $this;
    }

    public function getMessage(): ?AssistantMessage
    {
        return $this->message;
    }

    public function setMessage(?AssistantMessage $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): self
    {
        $this->entreprise = $entreprise;

        return $this;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): self
    {
        $this->auteur = $auteur;

        return $this;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): self
    {
        $this->titre = $titre;

        return $this;
    }

    public function getNomFichier(): ?string
    {
        return $this->nomFichier;
    }

    public function setNomFichier(string $nomFichier): self
    {
        $this->nomFichier = $nomFichier;

        return $this;
    }

    public function getFormat(): ?string
    {
        return $this->format;
    }

    public function setFormat(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function getMime(): ?string
    {
        return $this->mime;
    }

    public function setMime(string $mime): self
    {
        $this->mime = $mime;

        return $this;
    }

    public function getTaille(): int
    {
        return $this->taille;
    }

    public function setTaille(int $taille): self
    {
        $this->taille = $taille;

        return $this;
    }

    public function getCaracteres(): int
    {
        return $this->caracteres;
    }

    public function setCaracteres(int $caracteres): self
    {
        $this->caracteres = $caracteres;

        return $this;
    }

    public function getPages(): int
    {
        return $this->pages;
    }

    public function setPages(int $pages): self
    {
        $this->pages = $pages;

        return $this;
    }

    public function getCoutTokens(): int
    {
        return $this->coutTokens;
    }

    public function setCoutTokens(int $coutTokens): self
    {
        $this->coutTokens = $coutTokens;

        return $this;
    }

    public function getFichier(): ?File
    {
        return $this->fichier;
    }

    /**
     * Poser le binaire touche updatedAt : sans ce changement d'état, Doctrine ne
     * voit rien à écrire et Vich ne déplace jamais le fichier.
     */
    public function setFichier(?File $fichier): self
    {
        $this->fichier = $fichier;
        if ($fichier !== null) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getNomFichierStocke(): ?string
    {
        return $this->nomFichierStocke;
    }

    public function setNomFichierStocke(?string $nomFichierStocke): self
    {
        $this->nomFichierStocke = $nomFichierStocke;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
