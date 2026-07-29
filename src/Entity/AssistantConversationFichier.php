<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Fichier attaché par l'utilisateur au contexte d'une conversation avec
 * l'assistant IA (miroir de AssistantConversationContexte, côté fichiers). Le
 * binaire est stocké HORS public/ (mapping Vich « assistant_fichiers » →
 * var/uploads/assistant) et n'est servi que par une route de téléchargement
 * fail-closed scopée à la conversation. Un extrait de texte (best-effort, borné)
 * est capturé à l'upload : il alimente la section « PIÈCES JOINTES » du prompt
 * (lecture / extraction / recherche). Le couple conversation + id référence le
 * fichier ; Ket le rattache à un enregistrement via le marqueur « @fichier:<id> »
 * (cf. App\Ai\Mutation\ConversationFichierRef).
 */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class AssistantConversationFichier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'fichiers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AssistantConversation $conversation = null;

    /** Nom d'origine du fichier tel que déposé par l'utilisateur (affichage + téléchargement). */
    #[ORM\Column(length: 255)]
    private ?string $nomOriginal = null;

    /**
     * Binaire porté par Vich — NON persisté directement. Le déplacement physique
     * a lieu au flush ; le nom de stockage unique est écrit dans nomFichierStocke.
     */
    #[Vich\UploadableField(mapping: 'assistant_fichiers', fileNameProperty: 'nomFichierStocke')]
    private ?File $fichier = null;

    /** Nom de stockage unique sur le disque (sortie du SmartUniqueNamer). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomFichierStocke = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mimeType = null;

    /** Taille en octets (métrage + affichage). */
    #[ORM\Column]
    private int $taille = 0;

    /**
     * Extrait de texte capturé à l'upload (best-effort, tronqué) : formats texte
     * et PDF. Null si le format n'est pas lisible en phase 1 (images, docx, xlsx).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $texteExtrait = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

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

    public function getNomOriginal(): ?string
    {
        return $this->nomOriginal;
    }

    public function setNomOriginal(string $nomOriginal): self
    {
        $this->nomOriginal = $nomOriginal;
        return $this;
    }

    public function getFichier(): ?File
    {
        return $this->fichier;
    }

    public function setFichier(?File $fichier): self
    {
        $this->fichier = $fichier;
        // Vich détecte le changement de fichier via un champ daté qui bouge.
        if ($fichier !== null) {
            $this->updatedAt = new \DateTimeImmutable('now');
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

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;
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

    public function getTexteExtrait(): ?string
    {
        return $this->texteExtrait;
    }

    public function setTexteExtrait(?string $texteExtrait): self
    {
        $this->texteExtrait = $texteExtrait;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable('now');
    }
}
