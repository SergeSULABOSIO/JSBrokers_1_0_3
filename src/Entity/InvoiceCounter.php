<?php

namespace App\Entity;

use App\Repository\InvoiceCounterRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @file Compteur de numérotation des factures (une ligne par année civile).
 * @description Garantit une séquence de factures SANS TROU et sans collision :
 * le numéro suivant est obtenu par incrément VERROUILLÉ (SELECT … FOR UPDATE)
 * de la séquence de l'année, dans la transaction d'encaissement. Cf.
 * App\Token\InvoiceNumberService.
 */
#[ORM\Entity(repositoryClass: InvoiceCounterRepository::class)]
#[ORM\Table(name: 'invoice_counter')]
class InvoiceCounter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Année civile de la séquence (ex. 2026). */
    #[ORM\Column(unique: true)]
    private int $annee = 0;

    /** Dernier numéro attribué pour l'année (0 = aucune facture encore émise). */
    #[ORM\Column]
    private int $sequence = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAnnee(): int
    {
        return $this->annee;
    }

    public function setAnnee(int $annee): static
    {
        $this->annee = $annee;

        return $this;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function setSequence(int $sequence): static
    {
        $this->sequence = $sequence;

        return $this;
    }

    /** Incrémente et renvoie le nouveau numéro de séquence. */
    public function increment(): int
    {
        return ++$this->sequence;
    }
}
