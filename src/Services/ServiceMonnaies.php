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

    // ================= Variantes scopées par ENTREPRISE (utilisables hors requête) =================
    // Les méthodes ci-dessus dépendent de l'utilisateur connecté (connectedTo) ; celles-ci
    // reçoivent l'entreprise explicitement — nécessaires aux services (comptabilité du
    // courtier, exports) qui travaillent sur une entreprise donnée.

    /** @return Monnaie[] Monnaies configurées pour une entreprise donnée. */
    public function getMonnaiesPourEntreprise(Entreprise $entreprise): array
    {
        return $this->entityManager->getRepository(Monnaie::class)->findBy(['entreprise' => $entreprise]);
    }

    /** Monnaie LOCALE d'une entreprise (celle des paramètres, ex. CDF), ou null. */
    public function getMonnaieLocalePourEntreprise(Entreprise $entreprise): ?Monnaie
    {
        foreach ($this->getMonnaiesPourEntreprise($entreprise) as $monnaie) {
            if ($monnaie->isLocale() === true) {
                return $monnaie;
            }
        }

        return null;
    }

    /** Monnaie d'AFFICHAGE d'une entreprise (celle des rapports, ex. USD), ou null. */
    public function getMonnaieAffichagePourEntreprise(Entreprise $entreprise): ?Monnaie
    {
        foreach ($this->getMonnaiesPourEntreprise($entreprise) as $monnaie) {
            if ($monnaie->getFonction() === Monnaie::FONCTION_AFFICHAGE_UNIQUEMENT
                || $monnaie->getFonction() === Monnaie::FONCTION_SAISIE_ET_AFFICHAGE) {
                return $monnaie;
            }
        }

        return null;
    }

    /**
     * Convertit un montant saisi en monnaie LOCALE vers la monnaie d'AFFICHAGE de
     * l'entreprise, via le pivot USD : `tauxusd` = nombre d'unités de la monnaie
     * pour 1 USD (USD = 1.00). Ex. CDF (taux 2800) → USD : montant / 2800.
     * Défensif : sans monnaies configurées, sans taux exploitable ou si locale et
     * affichage sont identiques, le montant est retourné TEL QUEL (pas de conversion).
     */
    public function convertirLocaleVersAffichage(float $montant, Entreprise $entreprise): float
    {
        $locale    = $this->getMonnaieLocalePourEntreprise($entreprise);
        $affichage = $this->getMonnaieAffichagePourEntreprise($entreprise);
        if ($locale === null || $affichage === null || $locale->getCode() === $affichage->getCode()) {
            return $montant;
        }

        $tauxLocale    = (float) $locale->getTauxusd();
        $tauxAffichage = (float) $affichage->getTauxusd();
        if ($tauxLocale <= 0 || $tauxAffichage <= 0) {
            return $montant;
        }

        return $montant / $tauxLocale * $tauxAffichage;
    }
}
