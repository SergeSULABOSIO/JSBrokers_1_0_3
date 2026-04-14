<?php

namespace App\Services;

use App\Constantes\Constante;
use App\Entity\Entreprise;
use App\Entity\Monnaie;
use App\Entity\Taxe;
use App\Entity\Utilisateur;
use App\Repository\MonnaieRepository;
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
        /** @var MonnaieRepository $monnaieRepository */
        $monnaieRepository = $this->entityManager->getRepository(Monnaie::class);

        if ($this->getUtilisateurConnecte()) {
            if ($this->getUtilisateurConnecte()->getConnectedTo()) {
                /** @var Entreprise $entreprise */
                $entreprise = $this->getUtilisateurConnecte()->getConnectedTo();
                // MODIFICATION : On utilise le repository pour trouver les monnaies par entreprise.
                return $monnaieRepository->findBy(['entreprise' => $entreprise]);
            }
        }
        return [];
    }

    public function getMonnaieAffichage(): ?Monnaie
    {
        // MODIFICATION : On utilise la méthode modernisée getMonnaies().
        $monnaies = $this->getMonnaies();
        if (!empty($monnaies)) {
            foreach ($monnaies as $monnaie) {
                /** @var Monnaie $monnaie */
                if ($monnaie->getFonction() == Monnaie::FONCTION_AFFICHAGE_UNIQUEMENT || $monnaie->getFonction() == Monnaie::FONCTION_SAISIE_ET_AFFICHAGE) {
                    return $monnaie;
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
        $monnaies = $this->getMonnaies();
        if (!empty($monnaies)) {
            foreach ($monnaies as $monnaie) {
                /** @var Monnaie $monnaie */
                if ($monnaie->isLocale() == true) {
                    return $monnaie->getCode();
                }
            }
        }
        return "USD";
    }
}
