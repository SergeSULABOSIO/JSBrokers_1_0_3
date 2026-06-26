<?php

namespace App\Entity\Crm;

use App\Entity\Utilisateur;
use App\Repository\Crm\CrmCampagneCibleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @file Cible (destinataire) d'une campagne marketing CRM.
 * @description Trace l'envoi e-mail vers un client et une éventuelle conversion
 * (achat ultérieur). Permet de mesurer l'efficacité d'une campagne.
 */
#[ORM\Entity(repositoryClass: CrmCampagneCibleRepository::class)]
#[ORM\Table(name: 'crm_campagne_cible')]
class CrmCampagneCible
{
    public const ENVOI_EN_ATTENTE = 'en_attente';
    public const ENVOI_ENVOYE     = 'envoye';
    public const ENVOI_ECHEC      = 'echec';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CrmCampagne::class, inversedBy: 'cibles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CrmCampagne $campagne = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $client = null;

    #[ORM\Column(length: 12, options: ['default' => 'en_attente'])]
    private string $statutEnvoi = self::ENVOI_EN_ATTENTE;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $converti = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampagne(): ?CrmCampagne
    {
        return $this->campagne;
    }

    public function setCampagne(?CrmCampagne $campagne): static
    {
        $this->campagne = $campagne;

        return $this;
    }

    public function getClient(): ?Utilisateur
    {
        return $this->client;
    }

    public function setClient(?Utilisateur $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getStatutEnvoi(): string
    {
        return $this->statutEnvoi;
    }

    public function setStatutEnvoi(string $statutEnvoi): static
    {
        $this->statutEnvoi = $statutEnvoi;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function isConverti(): bool
    {
        return $this->converti;
    }

    public function setConverti(bool $converti): static
    {
        $this->converti = $converti;

        return $this;
    }
}
