<?php

namespace App\Services;

use App\Entity\Entreprise;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Entity\Utilisateur;
use ArrayObject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class ServiceTaxes
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
    ) {}

    public function getUtilisateurConnecte(): ?Utilisateur
    {
        return $this->security->getUser();
    }

    public function getTaxes()
    {
        if ($this->getUtilisateurConnecte()) {
            if ($this->getUtilisateurConnecte()->getConnectedTo()) {
                /** @var Entreprise $entreprise */
                $entreprise = $this->getUtilisateurConnecte()->getConnectedTo();
                return $entreprise->getTaxes();
            }
        }
        return [];
    }

    public function getTaxesPayableParCourtier()
    {
        $tab = [];
        if ($this->getTaxes()) {
            foreach ($this->getTaxes() as $taxe) {
                /** @var Taxe $tx */
                $tx = $taxe;
                if ($tx->getRedevable() == Taxe::REDEVABLE_COURTIER) {
                    $tab[] = $tx;
                }
            }
        }
        return $tab;
    }

    public function getTaxesPayableParAssureur()
    {
        $tab = [];
        if ($this->getTaxes()) {
            foreach ($this->getTaxes() as $taxe) {
                /** @var Taxe $tx */
                $tx = $taxe;
                if ($tx->getRedevable() == Taxe::REDEVABLE_ASSUREUR) {
                    $tab[] = $tx;
                }
            }
        }
        return $tab;
    }
}
