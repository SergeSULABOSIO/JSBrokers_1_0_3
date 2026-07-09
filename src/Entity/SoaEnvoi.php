<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\SoaEnvoiRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace d'un envoi du relevé de compte (SOA) par e-mail à un destinataire.
 * L'AuditableTrait fournit l'entreprise émettrice, l'invite expéditeur et la
 * date d'envoi (createdAt) ; on fige en plus la date d'expiration du lien telle
 * qu'annoncée au destinataire à ce moment-là (le jeton, lui, se prolonge).
 */
#[ORM\Entity(repositoryClass: SoaEnvoiRepository::class)]
#[ORM\HasLifecycleCallbacks]
class SoaEnvoi
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Client $client = null;

    #[ORM\Column(length: 255)]
    private ?string $emailDestinataire = null;

    #[ORM\Column(length: 255)]
    private ?string $nomDestinataire = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $lienExpireAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): self
    {
        $this->client = $client;
        return $this;
    }

    public function getEmailDestinataire(): ?string
    {
        return $this->emailDestinataire;
    }

    public function setEmailDestinataire(string $emailDestinataire): self
    {
        $this->emailDestinataire = $emailDestinataire;
        return $this;
    }

    public function getNomDestinataire(): ?string
    {
        return $this->nomDestinataire;
    }

    public function setNomDestinataire(string $nomDestinataire): self
    {
        $this->nomDestinataire = $nomDestinataire;
        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function getLienExpireAt(): ?\DateTimeImmutable
    {
        return $this->lienExpireAt;
    }

    public function setLienExpireAt(\DateTimeImmutable $lienExpireAt): self
    {
        $this->lienExpireAt = $lienExpireAt;
        return $this;
    }
}
