<?php

namespace App\Services\Canvas;

use App\Constantes\Constante;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use DateTimeImmutable;

class CalculationProvider
{
    /**
     * @param ServiceDates $serviceDates
     * @param Constante $constante
     */
    public function __construct(
        private ServiceDates $serviceDates,
        private Constante $constante,
        private ServiceTaxes $serviceTaxes
    ) {
    }

    /**
     * Calcule le délai en jours entre la survenance et la notification d'un sinistre.
     */
    public function Notification_Sinistre_getDelaiDeclaration(NotificationSinistre $sinistre): string
    {
        if (!$sinistre->getOccuredAt() || !$sinistre->getNotifiedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($sinistre->getOccuredAt(), $sinistre->getNotifiedAt());
        return $jours . ' jour(s)';
    }

    /**
     * Calcule l'âge du dossier sinistre depuis sa création.
     */
    public function Notification_Sinistre_getAgeDossier(NotificationSinistre $sinistre): string
    {
        if (!$sinistre->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($sinistre->getCreatedAt(), new DateTimeImmutable());
        return $jours . ' jour(s)';
    }

    /**
     * Calcule le pourcentage de pièces fournies par rapport aux pièces attendues.
     */
    public function Notification_Sinistre_getIndiceCompletude(NotificationSinistre $sinistre): string
    {
        $attendus = count($this->constante->getEnterprise()->getModelePieceSinistres());
        if ($attendus === 0) {
            return '100 %'; // S'il n'y a aucune pièce modèle, le dossier est complet.
        }
        $fournis = count($sinistre->getPieces());
        $pourcentage = ($fournis / $attendus) * 100;
        return round($pourcentage) . ' %';
    }

    /**
     * Calcule le pourcentage payé d'une offre d'indemnisation.
     */
    public function Offre_Indemnisation_getPourcentagePaye(OffreIndemnisationSinistre $offre): string
    {
        $montantPayable = $offre->getMontantPayable();
        if ($montantPayable == 0 || $montantPayable === null) {
            return '100 %'; // Si rien n'est à payer, c'est considéré comme payé.
        }
        $totalVerse = $this->constante->Offre_Indemnisation_getCompensationVersee($offre);
        $pourcentage = ($totalVerse / $montantPayable) * 100;
        return round($pourcentage) . ' %';
    }

        /**
     * Calcule le montant total de l'indemnisation convenue pour ce sinistre.
     */
    public function Notification_Sinistre_getCompensation(NotificationSinistre $sinistre): float
    {
        $compensation = 0;
        if ($sinistre != null) {
            foreach ($sinistre->getOffreIndemnisationSinistres() as $offre_indemnisation) {
                $compensation += $offre_indemnisation->getMontantPayable();
            }
        }
        return $compensation;
    }

    /**
     * Calcule le montant cumulé des paiements déjà effectués pour cette indemnisation.
     */
    public function Notification_Sinistre_getCompensationVersee(NotificationSinistre $sinistre): float
    {
        $montant = 0;
        if ($sinistre != null) {
            foreach ($sinistre->getOffreIndemnisationSinistres() as $offre_indemnisation) {
                $montant += $this->Offre_Indemnisation_getCompensationVersee($offre_indemnisation);
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant restant à payer pour solder complètement ce dossier sinistre.
     */
    public function Notification_Sinistre_getSoldeAVerser(NotificationSinistre $sinistre): float
    {
        $montant = 0;
        if ($sinistre != null) {
            foreach ($sinistre->getOffreIndemnisationSinistres() as $offre_indemnisation) {
                $montant += $this->Offre_Indemnisation_getSoldeAVerser($offre_indemnisation);
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant de la franchise qui a été appliquée conformément aux termes de la police.
     */
    public function Notification_Sinistre_getFranchise(NotificationSinistre $sinistre): float
    {
        $montant = 0;
        if ($sinistre != null) {
            foreach ($sinistre->getOffreIndemnisationSinistres() as $offre_indemnisation) {
                $montant += $offre_indemnisation->getFranchiseAppliquee();
            }
        }
        return $montant;
    }

    /**
     * Calcule la durée totale en jours entre la notification du sinistre et le dernier paiement de règlement.
     */
    public function Notification_Sinistre_getDureeReglement(NotificationSinistre $notification_sinistre): int
    {
        $duree = -1;
        $dateNotfication = $notification_sinistre->getNotifiedAt();
        $dateRgelement = null;
        if ($this->Notification_Sinistre_getSoldeAVerser($notification_sinistre) == 0) {
            $offres = $notification_sinistre->getOffreIndemnisationSinistres();
            if (count($offres) != 0) {
                $reglements = ($offres[count($offres) - 1])->getPaiements();
                $dateRgelement = ($reglements[count($reglements) - 1])->getPaidAt();
                $duree = $this->serviceDates->daysEntre($dateNotfication, $dateRgelement);
            }
        }
        return $duree;
    }

    /**
     * Récupère la date à laquelle le tout dernier paiement a été effectué pour ce sinistre.
     */
    public function Notification_Sinistre_getDateDernierRgelement(NotificationSinistre $notification_sinistre): ?\DateTimeInterface
    {
        $dateDernierRgelement = null;
        if ($this->Notification_Sinistre_getSoldeAVerser($notification_sinistre) == 0) {
            $offres = $notification_sinistre->getOffreIndemnisationSinistres();
            if (count($offres) != 0) {
                $reglements = ($offres[count($offres) - 1])->getPaiements();
                $dateDernierRgelement = ($reglements[count($reglements) - 1])->getPaidAt();
            }
        }
        return $dateDernierRgelement;
    }

    /**
     * Calcule le montant cumulé des paiements déjà effectués pour cette offre.
     */
    public function Offre_Indemnisation_getCompensationVersee(OffreIndemnisationSinistre $offre_indemnisation): float
    {
        $montant = 0;
        if ($offre_indemnisation != null) {
            foreach ($offre_indemnisation->getPaiements() as $paiement) {
                $montant += $paiement->getMontant();
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant restant à payer pour solder cette offre.
     */
    public function Offre_Indemnisation_getSoldeAVerser(OffreIndemnisationSinistre $offre_indemnisation): float
    {
        $montant = 0;
        if ($offre_indemnisation != null) {
            $compensation = 0;
            if ($offre_indemnisation->getNotificationSinistre() != null) {
                $compensation = $offre_indemnisation->getMontantPayable();
            }
            $compensationVersee = $this->Offre_Indemnisation_getCompensationVersee($offre_indemnisation);
            $montant = $compensation - $compensationVersee;
        }
        return $montant;
    }

    /**
     * Calcule le montant total de la prime et de la commission pour une entreprise.
     *
     * @param Entreprise $entreprise L'entreprise pour laquelle calculer les montants.
     * @param boolean $isBound Si true, ne calcule que pour les polices souscrites (avec avenant). Sinon, pour toutes les propositions.
     * @return array Un tableau associatif avec 'prime_totale' and 'commission_totale'.
     */
    public function getMontants(Entreprise $entreprise, bool $isBound): array
    {
        $prime_totale = 0;
        $commission_totale = 0;

        foreach ($entreprise->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                $process = false;
                if ($isBound) {
                    if ($this->pisteIsBound($piste)) {
                        $process = true;
                    }
                } else {
                    $process = true;
                }

                if ($process) {
                    $prime_totale += $this->getPisteMontantPrimePayableParClient($piste);
                    $commission_totale += $this->getPisteMontantCommissionTtc($piste, -1, false);
                }
            }
        }

        return [
            'prime_totale' => $prime_totale,
            'commission_totale' => $commission_totale,
        ];
    }

    private function pisteIsBound(?Piste $piste): bool
    {
        if ($piste) {
            foreach ($piste->getCotations() as $cotation) {
                if (count($cotation->getAvenants()) > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    private function getPisteMontantPrimePayableParClient(?Piste $piste): float
    {
        $total = 0;
        if ($piste) {
            foreach ($piste->getCotations() as $cotation) {
                $total += $this->getCotationMontantPrimePayableParClient($cotation);
            }
        }
        return $total;
    }

    private function getCotationMontantPrimePayableParClient(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getChargements() as $chargement) {
                $montant += $chargement->getMontantFlatExceptionel();
            }
        }
        return $montant;
    }

    private function getPisteMontantCommissionTtc(?Piste $piste, int $addressedTo, bool $onlySharable): float
    {
        $total = 0;
        if ($piste) {
            foreach ($piste->getCotations() as $cotation) {
                $total += $this->getCotationMontantCommissionTtc($cotation, $addressedTo, $onlySharable);
            }
        }
        return $total;
    }

    private function getCotationMontantCommissionTtc(?Cotation $cotation, ?int $addressedTo, bool $onlySharable): float
    {
        if (!$cotation) return 0;

        $comTTCAssureur = $this->getCotationMontantCommissionTtcPayableParAssureur($cotation, $onlySharable);
        $comTTCClient = $this->getCotationMontantCommissionTtcPayableParClient($cotation, $onlySharable);
        return round($comTTCAssureur + $comTTCClient, 2);
    }

    private function getCotationMontantCommissionTtcPayableParAssureur(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_ASSUREUR, $onlySharable);
        $taxe = $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
        return $net + $taxe;
    }

    private function getCotationMontantCommissionTtcPayableParClient(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_CLIENT, $onlySharable);
        $taxe = $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
        return $net + $taxe;
    }

    private function isIARD(?Cotation $cotation): bool
    {
        if ($cotation && $cotation->getPiste() && $cotation->getPiste()->getRisque()) {
            return $cotation->getPiste()->getRisque()->getBranche() == Risque::BRANCHE_IARD_OU_NON_VIE;
        }
        return false;
    }

    private function getCotationMontantCommissionHt(?Cotation $cotation, int $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getRevenus() as $revenu) {
                $shouldProcess = !$onlySharable || ($revenu->getTypeRevenu()->isShared());
                if ($shouldProcess) {
                    $montant += $this->getRevenuMontantHtAddressedTo($addressedTo, $revenu);
                }
            }
        }
        return $montant;
    }

    private function getRevenuMontantHtAddressedTo(int $addressedTo, RevenuPourCourtier $revenu): float
    {
        if ($addressedTo !== -1) {
            if ($revenu->getTypeRevenu()->getRedevable() == $addressedTo) {
                return $this->getRevenuMontantHt($revenu);
            }
            return 0;
        }
        return $this->getRevenuMontantHt($revenu);
    }

    private function getRevenuMontantHt(?RevenuPourCourtier $revenu): float
    {
        $montant = 0;
        if ($revenu) {
            $typeRevenu = $revenu->getTypeRevenu();
            $cotation = $revenu->getCotation();
            $montantChargementPrime = $this->getCotationMontantChargementPrime($cotation, $typeRevenu);

            if ($typeRevenu->isAppliquerPourcentageDuRisque()) {
                $risque = $this->getCotationRisque($cotation);
                if ($risque) {
                    $montant += $montantChargementPrime * $risque->getPourcentageCommissionSpecifiqueHT();
                }
            } else {
                if ($revenu->getTauxExceptionel() != 0) {
                    $montant += $montantChargementPrime * $revenu->getTauxExceptionel();
                } elseif ($revenu->getMontantFlatExceptionel() != 0) {
                    $montant += $revenu->getMontantFlatExceptionel();
                } elseif ($typeRevenu->getPourcentage() != 0) {
                    $montant += $montantChargementPrime * $typeRevenu->getPourcentage();
                } elseif ($typeRevenu->getMontantflat() != 0) {
                    $montant += $montantChargementPrime * $typeRevenu->getMontantflat();
                }
            }
        }
        return $montant;
    }

    private function getCotationMontantChargementPrime(?Cotation $cotation, ?TypeRevenu $typeRevenu): float
    {
        $montantChargementCible = 0;
        if ($cotation && $typeRevenu) {
            foreach ($cotation->getChargements() as $loading) {
                if ($loading->getType() == $typeRevenu->getTypeChargement()) {
                    $montantChargementCible = $loading->getMontantFlatExceptionel();
                }
            }
        }
        return $montantChargementCible;
    }

    private function getCotationRisque(?Cotation $cotation): ?Risque
    {
        if ($cotation && $cotation->getPiste()) {
            return $cotation->getPiste()->getRisque();
        }
        return null;
    }

}