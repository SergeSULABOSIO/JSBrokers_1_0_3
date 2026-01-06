<?php

namespace App\Services\Canvas;

use App\Constantes\Constante;
use App\Entity\Avenant;
use App\Entity\Assureur;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Partenaire;
use App\Entity\Risque;
use App\Entity\Tranche;
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
     * @param Entreprise $entreprise L'entreprise de base pour le calcul.
     * @param boolean $isBound Si true, ne calcule que pour les polices souscrites (avec avenant).
     * @param array $options Tableau de filtres optionnels. Peut contenir : 'pisteCible', 'cotationCible', 'assureurCible', 'risqueCible', 'partenaireCible', 'inviteCible', 'groupeCible', 'avenantCible', 'clientCible', 'trancheCible', 'brancheCible', 'reper' ('deteEffet' ou 'echeance'), 'entre', 'et'.
     * @return array Un tableau associatif avec 'prime_totale' and 'commission_totale'.
     */
    public function getMontants(Entreprise $entreprise, bool $isBound, array $options = []): array
    {
        $prime_totale = 0;
        $commission_totale = 0;

        // 1. Extract filters from options
        /** @var Piste|null $pisteCible */
        $pisteCible = $options['pisteCible'] ?? null;
        /** @var Cotation|null $cotationCible */
        $cotationCible = $options['cotationCible'] ?? null;
        /** @var Assureur|null $assureurCible */
        $assureurCible = $options['assureurCible'] ?? null;
        /** @var Risque|null $risqueCible */
        $risqueCible = $options['risqueCible'] ?? null;
        /** @var Partenaire|null $partenaireCible */
        $partenaireCible = $options['partenaireCible'] ?? null;
        /** @var Invite|null $inviteCible */
        $inviteCible = $options['inviteCible'] ?? null;
        /** @var Groupe|null $groupeCible */
        $groupeCible = $options['groupeCible'] ?? null;
        /** @var Avenant|null $avenantCible */
        $avenantCible = $options['avenantCible'] ?? null;
        /** @var Client|null $clientCible */
        $clientCible = $options['clientCible'] ?? null;
        /** @var Tranche|null $trancheCible */
        $trancheCible = $options['trancheCible'] ?? null;
        /** @var string|null $brancheCible */
        $brancheCible = $options['brancheCible'] ?? null;
        /** @var string|null $reper */
        $reper = $options['reper'] ?? null;
        /** @var string|null $dateA_str */
        $dateA_str = $options['entre'] ?? null;
        /** @var string|null $dateB_str */
        $dateB_str = $options['et'] ?? null;

        // 2. Get initial pool of all cotations from the Entreprise
        $cotationsAcalculer = [];
        foreach ($entreprise->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                foreach ($piste->getCotations() as $cotation) {
                    $cotationsAcalculer[] = $cotation;
                }
            }
        }

        // 3. Apply filters sequentially
        if ($avenantCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, fn (Cotation $cotation) => $cotation->getAvenants()->contains($avenantCible));
        }
        if ($cotationCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, fn (Cotation $cotation) => $cotation === $cotationCible);
        }
        if ($trancheCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, fn (Cotation $cotation) => $trancheCible->getCotation() === $cotation);
        }
        if ($pisteCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, fn (Cotation $cotation) => $cotation->getPiste() === $pisteCible);
        }
        if ($assureurCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, fn (Cotation $cotation) => $cotation->getAssureur() === $assureurCible);
        }
        if ($risqueCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, function (Cotation $cotation) use ($risqueCible) {
                return $cotation->getPiste() && $cotation->getPiste()->getRisque() === $risqueCible;
            });
        }
        if ($inviteCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, function (Cotation $cotation) use ($inviteCible) {
                return $cotation->getPiste() && $cotation->getPiste()->getInvite() === $inviteCible;
            });
        }
        if ($groupeCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, function (Cotation $cotation) use ($groupeCible) {
                return $cotation->getPiste() && $cotation->getPiste()->getClient() && $cotation->getPiste()->getClient()->getGroupe() === $groupeCible;
            });
        }
        if ($clientCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, function (Cotation $cotation) use ($clientCible) {
                return $cotation->getPiste() && $cotation->getPiste()->getClient() === $clientCible;
            });
        }
        if ($partenaireCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, function (Cotation $cotation) use ($partenaireCible) {
                if (!$cotation->getPiste()) return false;
                if ($cotation->getPiste()->getPartenaires()->contains($partenaireCible)) return true;
                return $cotation->getPiste()->getClient() && $cotation->getPiste()->getClient()->getPartenaires()->contains($partenaireCible);
            });
        }
        if ($brancheCible) {
            $brancheCode = -1;
            if ($brancheCible === 'IARD') {
                $brancheCode = Risque::BRANCHE_IARD_OU_NON_VIE;
            } elseif ($brancheCible === 'VIE') {
                $brancheCode = Risque::BRANCHE_VIE;
            }

            if ($brancheCode !== -1) {
                $cotationsAcalculer = array_filter($cotationsAcalculer, fn (Cotation $cotation) => $cotation->getPiste() && $cotation->getPiste()->getRisque() && $cotation->getPiste()->getRisque()->getBranche() === $brancheCode);
            }
        }
        if ($reper && $dateA_str && $dateB_str) {
            $dateA = DateTimeImmutable::createFromFormat('d/m/Y', $dateA_str);
            $dateB = DateTimeImmutable::createFromFormat('d/m/Y', $dateB_str);

            if ($dateA && $dateB) {
                $dateA = $dateA->setTime(0, 0, 0);
                $dateB = $dateB->setTime(23, 59, 59);

                $cotationsAcalculer = array_filter($cotationsAcalculer, function (Cotation $cotation) use ($reper, $dateA, $dateB) {
                    foreach ($cotation->getAvenants() as $avenant) {
                        $dateToCheck = null;
                        if ($reper === 'dateEffet') {
                            $dateToCheck = $avenant->getStartingAt();
                        } elseif ($reper === 'echeance') {
                            $dateToCheck = $avenant->getEndingAt();
                        }

                        if ($dateToCheck && $dateToCheck >= $dateA && $dateToCheck <= $dateB) {
                            return true; // Keep this cotation
                        }
                    }
                    return false; // Discard this cotation
                });
            }
        }

        // 4. Calculate totals from the filtered list
        foreach ($cotationsAcalculer as $cotation) {
            if ($isBound && !$this->cotationIsBound($cotation)) {
                continue; // On saute les cotations non-souscrites si isBound est true.
            }

            $prime_totale += $this->getCotationMontantPrimePayableParClient($cotation);
            $commission_totale += $this->getCotationMontantCommissionTtc($cotation, -1, false);
        }

        // 5. Apply tranche percentage if provided
        if ($trancheCible) {
            $pourcentage = $trancheCible->getPourcentage();
            if ($pourcentage !== null) {
                $prime_totale *= $pourcentage;
                $commission_totale *= $pourcentage;
            }
        }

        return [
            'prime_totale' => $prime_totale,
            'commission_totale' => $commission_totale,
        ];
    }

    private function cotationIsBound(?Cotation $cotation): bool
    {
        return $cotation && count($cotation->getAvenants()) > 0;
    }

    private function pisteIsBound(?Piste $piste): bool
    {
        if ($piste) {
            foreach ($piste->getCotations() as $cotation) {
                if ($this->cotationIsBound($cotation)) {
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
                $typeRevenu = $revenu->getTypeRevenu();
                if ($typeRevenu) {
                    $shouldProcess = !$onlySharable || $typeRevenu->isShared();
                    if ($shouldProcess) {
                        $montant += $this->getRevenuMontantHtAddressedTo($addressedTo, $revenu);
                    }
                }
            }
        }
        return $montant;
    }

    private function getRevenuMontantHtAddressedTo(int $addressedTo, RevenuPourCourtier $revenu): float
    {
        $typeRevenu = $revenu->getTypeRevenu();
        if (!$typeRevenu) {
            return 0;
        }

        if ($addressedTo !== -1) {
            if ($typeRevenu->getRedevable() == $addressedTo) {
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
            if ($typeRevenu) {
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