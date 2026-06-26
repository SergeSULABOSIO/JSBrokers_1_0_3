<?php

namespace App\Entity\Crm;

use App\Repository\Crm\CrmAutomationLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @file Journal d'idempotence des automatisations CRM.
 * @description Une ligne par déclenchement (règle + clé d'entité) pour éviter
 * qu'une même automatisation se redéclenche (ex. alerte de solde bas émise une
 * seule fois par cycle). La contrainte d'unicité (regle, cle_entite) garantit
 * l'idempotence même en cas d'exécutions concurrentes.
 */
#[ORM\Entity(repositoryClass: CrmAutomationLogRepository::class)]
#[ORM\Table(name: 'crm_automation_log')]
#[ORM\UniqueConstraint(name: 'UNIQ_CRM_AUTOLOG', columns: ['regle', 'cle_entite'])]
#[ORM\HasLifecycleCallbacks]
class CrmAutomationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    private ?string $regle = null;

    #[ORM\Column(length: 120)]
    private ?string $cleEntite = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $firedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRegle(): ?string
    {
        return $this->regle;
    }

    public function setRegle(?string $regle): static
    {
        $this->regle = $regle;

        return $this;
    }

    public function getCleEntite(): ?string
    {
        return $this->cleEntite;
    }

    public function setCleEntite(?string $cleEntite): static
    {
        $this->cleEntite = $cleEntite;

        return $this;
    }

    public function getFiredAt(): ?\DateTimeImmutable
    {
        return $this->firedAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->firedAt ??= new \DateTimeImmutable();
    }
}
