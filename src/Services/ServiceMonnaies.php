<?php

namespace App\Services;

use App\Constantes\Constante;
use App\Entity\Entreprise;
use App\Entity\Monnaie;
use App\Entity\Taxe;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class ServiceMonnaies
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
    ) {}

    public function getUtilisateurConnecte(): ?Utilisateur
    {
        return $this->security->getUser();
    }

    public function getMonnaies()
    {
        if ($this->getUtilisateurConnecte()) {
            if ($this->getUtilisateurConnecte()->getConnectedTo()) {
                /** @var Entreprise $entreprise */
                $entreprise = $this->getUtilisateurConnecte()->getConnectedTo();
                return $entreprise->getMonnaies();
            }
        }
        return [];
    }

    public function getMonnaieAffichage(): ?Monnaie
    {
        if ($this->getUtilisateurConnecte()) {
            if ($this->getUtilisateurConnecte()->getConnectedTo()) {
                /** @var Entreprise $entreprise */
                $entreprise = $this->getUtilisateurConnecte()->getConnectedTo();
                foreach ($entreprise->getMonnaies() as $monnaie) {
                    if ($monnaie->getFonction() == Monnaie::FONCTION_AFFICHAGE_UNIQUEMENT || $monnaie->getFonction() == Monnaie::FONCTION_SAISIE_ET_AFFICHAGE) {
                        return $monnaie;
                    }
                }
            }
        }
        return null;
    }

    public function getCodeMonnaieAffichage()
    {
        /** @var Monnaie $monnaieAffichage */
        $monnaie = $this->getMonnaieAffichage();
        if ($monnaie) {
            return $monnaie->getCode();
        }
        return null;
    }

    public function getCodeMonnaieLocale(): ?string
    {
        if ($this->getUtilisateurConnecte()) {
            if ($this->getUtilisateurConnecte()->getConnectedTo()) {
                /** @var Entreprise $entreprise */
                $entreprise = $this->getUtilisateurConnecte()->getConnectedTo();
                /** @var Monnaie $monnaie */
                foreach ($entreprise->getMonnaies() as $monnaie) {
                    if ($monnaie->isLocale() == true) {
                        return $monnaie->getCode();
                    }
                }
            }
        }
        return "USD";
    }
}
