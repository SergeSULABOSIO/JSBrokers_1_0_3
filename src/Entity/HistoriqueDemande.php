<?php

namespace App\Entity;

use App\Entity\Traits\AuditableTrait;
use App\Repository\HistoriqueDemandeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @file Trace d'une transition d'état d'une demande de congé.
 * @description Toute transition en écrit une ligne, sans exception : c'est ce qui permet
 * de répondre à « qui a approuvé, quand, et pourquoi » des mois plus tard.
 *
 * La ligne porte aussi le CANAL (`origine`) : l'historique doit pouvoir dire
 * « approuvée par X via Ket ». L'auteur enregistré reste l'humain — l'assistant n'est
 * jamais l'auteur d'une décision, seulement son moyen.
 *
 * C'est la création d'une ligne d'historique qui déclenche l'e-mail correspondant : une
 * transition notifiée est une transition tracée, et réciproquement.
 */
#[ORM\Entity(repositoryClass: HistoriqueDemandeRepository::class)]
#[ORM\HasLifecycleCallbacks]
class HistoriqueDemande implements OwnerAwareInterface
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['list:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'historiques')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?DemandeConge $demande = null;

    /** Null pour la toute première ligne (création de la demande). */
    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $statutAvant = null;

    #[ORM\Column(length: 20)]
    #[Groups(['list:read'])]
    private ?string $statutApres = null;

    /** L'humain à l'origine de la transition — jamais l'assistant. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Invite $auteur = null;

    #[ORM\Column(length: 10)]
    #[Groups(['list:read'])]
    private string $origine = DemandeConge::ORIGINE_UI;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['list:read'])]
    private ?string $commentaire = null;

    /**
     * La décision a-t-elle été rendue par le demandeur lui-même, faute d'un autre
     * valideur ? Portée par la ligne pour que la mention « auto-approuvée » soit dans
     * l'historique et dans le mail sans se déduire d'une comparaison refaite partout.
     */
    #[ORM\Column]
    #[Groups(['list:read'])]
    private bool $autoApprouvee = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDemande(): ?DemandeConge
    {
        return $this->demande;
    }

    public function setDemande(?DemandeConge $demande): static
    {
        $this->demande = $demande;

        return $this;
    }

    public function getStatutAvant(): ?string
    {
        return $this->statutAvant;
    }

    public function setStatutAvant(?string $statutAvant): static
    {
        $this->statutAvant = $statutAvant;

        return $this;
    }

    public function getStatutApres(): ?string
    {
        return $this->statutApres;
    }

    public function setStatutApres(?string $statutApres): static
    {
        $this->statutApres = $statutApres;

        return $this;
    }

    public function getAuteur(): ?Invite
    {
        return $this->auteur;
    }

    public function setAuteur(?Invite $auteur): static
    {
        $this->auteur = $auteur;

        return $this;
    }

    public function getOrigine(): string
    {
        return $this->origine;
    }

    public function setOrigine(string $origine): static
    {
        $this->origine = $origine;

        return $this;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): static
    {
        $this->commentaire = $commentaire;

        return $this;
    }

    public function isAutoApprouvee(): bool
    {
        return $this->autoApprouvee;
    }

    public function setAutoApprouvee(bool $autoApprouvee): static
    {
        $this->autoApprouvee = $autoApprouvee;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('%s → %s', $this->statutAvant ?? '—', $this->statutApres ?? '?');
    }
}
