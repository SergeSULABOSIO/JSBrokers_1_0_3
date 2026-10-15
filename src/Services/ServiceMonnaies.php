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
                // ⚠ ORDRE STABLE : sans lui, « la première monnaie d'affichage » dépendait
                // de l'ordre que la base voulait bien rendre.
                return $monnaieRepository->findBy(['entreprise' => $entreprise], ['id' => 'ASC']);
            }
        }
        return [];
    }

    /**
     * La monnaie de TRAVAIL du cabinet connecté : celle de la saisie et de l'affichage — il
     * n'y en a qu'une (MonnaieDeTravail). Aucun montant n'est jamais converti.
     */
    public function getMonnaieAffichage(): ?Monnaie
    {
        return self::monnaieDeTravail($this->getMonnaies());
    }

    /**
     * La monnaie de travail parmi celles d'un cabinet (triées par id) : la PREMIÈRE qui
     * s'affiche — « saisie et affichage », ou une ancienne « affichage uniquement ».
     *
     * ⚠ LA PREMIÈRE PAR ID, SANS PRÉFÉRER L'UNE DES DEUX FONCTIONS. C'est la règle qui a
     * toujours choisi l'étiquette posée à côté des montants pendant leur saisie ; en changer
     * ferait lire d'anciens montants dans une autre monnaie. Même règle que
     * MonnaieDeTravail::estLaRetenue() et que la migration du 2026-10-15.
     *
     * @param Monnaie[] $monnaies
     */
    private static function monnaieDeTravail(array $monnaies): ?Monnaie
    {
        foreach ($monnaies as $monnaie) {
            if (in_array($monnaie->getFonction(), [Monnaie::FONCTION_SAISIE_ET_AFFICHAGE, Monnaie::FONCTION_AFFICHAGE_UNIQUEMENT], true)) {
                return $monnaie;
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
        return $this->entityManager->getRepository(Monnaie::class)->findBy(['entreprise' => $entreprise], ['id' => 'ASC']);
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

    /** La monnaie de TRAVAIL d'une entreprise (saisie = affichage, ex. USD), ou null. */
    public function getMonnaieAffichagePourEntreprise(Entreprise $entreprise): ?Monnaie
    {
        return self::monnaieDeTravail($this->getMonnaiesPourEntreprise($entreprise));
    }

    /**
     * Convertit un montant exprimé en monnaie LOCALE (le pays) vers la monnaie de travail
     * de l'entreprise, via le pivot USD : `tauxusd` = nombre d'unités de la monnaie
     * pour 1 USD (USD = 1.00). Ex. CDF (taux 2800) → USD : montant / 2800.
     *
     * ⚠ SON SEUL USAGE EST LE CAPITAL SOCIAL, saisi dans la monnaie du pays pour les
     * mentions légales. Les montants du métier, eux, sont dans la monnaie de travail et ne
     * sont JAMAIS convertis.
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
