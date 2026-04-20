<?php

namespace App\Services;

use App\Entity\AutoriteFiscale;
use App\Entity\Taxe;
use App\Entity\Utilisateur;
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
        // Les taxes sont globales au système et non liées à une entreprise spécifique.
        return $this->entityManager->getRepository(Taxe::class)->findAll();
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

    public function getMontantTaxe($montantNet, bool $tauxIARD, bool $taxeAssureur)
    {
        $gross = 0;
        if ($taxeAssureur == true) {
            foreach ($this->getTaxesPayableParAssureur() as $taxeAss) {
                dump("TAUX BRUT TAXE ASSUREUR (IARD / VIE):", $taxeAss->getTauxIARD(), $taxeAss->getTauxVIE());
                $gross += match ($tauxIARD) {
                    true => $montantNet * ($taxeAss->getTauxIARD() / 100),
                    false => $montantNet * ($taxeAss->getTauxVIE() / 100),
                };
            }
        } else {
            foreach ($this->getTaxesPayableParCourtier() as $taxeCou) {
                dump("TAUX BRUT TAXE COURTIER (IARD / VIE):", $taxeCou->getTauxIARD(), $taxeCou->getTauxVIE());
                $gross += match ($tauxIARD) {
                    true => $montantNet * ($taxeCou->getTauxIARD() / 100),
                    false => $montantNet * ($taxeCou->getTauxVIE() / 100),
                };
            }
        }

        return $gross;
    }


    public function getMontantTaxeAutorite($montantNet, ?bool $tauxIARD, ?AutoriteFiscale $autoriteFiscale)
    {
        $montantTaxe = 0;
        foreach ($this->getTaxes() as $taxe) {
            if ($autoriteFiscale->getTaxe() == $taxe) {
                $montantTaxe += match ($tauxIARD) {
                    true => $montantNet * $taxe->getTauxIARD(),
                    false => $montantNet * $taxe->getTauxVIE(),
                };
            }
        }
        return $montantTaxe;
    }
}
