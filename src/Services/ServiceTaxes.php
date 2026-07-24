<?php

namespace App\Services;

use App\Entity\AutoriteFiscale;
use App\Entity\Entreprise;
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

    /**
     * Taxes SCOPÉES à une entreprise (colonne entreprise_id). findAll() renvoyait
     * celles de TOUTES les entreprises → getMontantTaxe les sommait toutes (ex. 3
     * entreprises avec une TVA 16% → taxe appliquée ×3, et pire à chaque nouvelle
     * entreprise). L'entreprise cible est, dans l'ordre : celle passée explicitement
     * (contextes SANS utilisateur connecté, ex. écritures comptables/suivi fiscal),
     * sinon l'entreprise active de l'utilisateur (contexte workspace HTTP). Aucune →
     * liste vide (jamais toutes les entreprises).
     */
    public function getTaxes(?Entreprise $entreprise = null)
    {
        $entreprise ??= $this->getUtilisateurConnecte()?->getConnectedTo();
        if ($entreprise === null) {
            return [];
        }

        return $this->entityManager->getRepository(Taxe::class)->findBy(['entreprise' => $entreprise]);
    }

    public function getTaxesPayableParCourtier(?Entreprise $entreprise = null)
    {
        return array_values(array_filter(
            $this->getTaxes($entreprise),
            static fn (Taxe $tx) => $tx->getRedevable() == Taxe::REDEVABLE_COURTIER,
        ));
    }

    public function getTaxesPayableParAssureur(?Entreprise $entreprise = null)
    {
        return array_values(array_filter(
            $this->getTaxes($entreprise),
            static fn (Taxe $tx) => $tx->getRedevable() == Taxe::REDEVABLE_ASSUREUR,
        ));
    }

    /**
     * NOUVEAU : Récupère l'objet Taxe applicable.
     *
     * @param boolean $isTaxeAssureur
     * @return Taxe|null
     */
    public function getTaxeApplicable(bool $isIARD, bool $isTaxeAssureur, ?Entreprise $entreprise = null): ?Taxe
    {
        $taxes = $isTaxeAssureur ? $this->getTaxesPayableParAssureur($entreprise) : $this->getTaxesPayableParCourtier($entreprise);
        // Dans la logique actuelle, il n'y a qu'une seule taxe par redevable.
        // On retourne donc la première trouvée.
        return count($taxes) > 0 ? $taxes[0] : null;
    }


    public function getMontantTaxe($montantNet, bool $tauxIARD, bool $taxeAssureur, ?Entreprise $entreprise = null)
    {
        $gross = 0;
        if ($taxeAssureur == true) {
            foreach ($this->getTaxesPayableParAssureur($entreprise) as $taxeAss) {
                $gross += match ($tauxIARD) {
                    true => $montantNet * ($taxeAss->getTauxIARD() / 100),
                    false => $montantNet * ($taxeAss->getTauxVIE() / 100),
                };
            }
        } else {
            foreach ($this->getTaxesPayableParCourtier($entreprise) as $taxeCou) {
                $gross += match ($tauxIARD) {
                    true => $montantNet * ($taxeCou->getTauxIARD() / 100),
                    false => $montantNet * ($taxeCou->getTauxVIE() / 100),
                };
            }
        }

        return $gross;
    }


    public function getMontantTaxeAutorite($montantNet, ?bool $tauxIARD, ?AutoriteFiscale $autoriteFiscale, ?Entreprise $entreprise = null)
    {
        $montantTaxe = 0;
        foreach ($this->getTaxes($entreprise) as $taxe) {
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
