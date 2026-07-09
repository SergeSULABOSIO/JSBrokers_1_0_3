<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\SoaAccesTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Jeton d'accès public au relevé de compte (SOA) d'un client.
 * Porte l'entreprise émettrice (via AuditableTrait) car Client ne suffit pas
 * à lui seul à reconstituer le contexte du SOA hors session workspace.
 */
#[ORM\Entity(repositoryClass: SoaAccesTokenRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_soa_acces_token', columns: ['token'])]
class SoaAccesToken
{
    use AuditableTrait;

    public const VALIDITE_JOURS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private ?string $token = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Client $client = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAccessedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $accessCount = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;
        return $this;
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

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): self
    {
        $this->revokedAt = $revokedAt;
        return $this;
    }

    public function getLastAccessedAt(): ?\DateTimeImmutable
    {
        return $this->lastAccessedAt;
    }

    public function setLastAccessedAt(?\DateTimeImmutable $lastAccessedAt): self
    {
        $this->lastAccessedAt = $lastAccessedAt;
        return $this;
    }

    public function getAccessCount(): int
    {
        return $this->accessCount;
    }

    public function incrementAccessCount(): self
    {
        $this->accessCount++;
        return $this;
    }

    public function isActif(\DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null
            && $this->expiresAt !== null
            && $this->expiresAt > $now;
    }
}
