<?php

namespace App\Services\Canvas;


use App\Entity\Note;
use App\Entity\Piste;
use App\Entity\Client;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\Risque;
use DateTimeImmutable;
use App\Entity\Avenant;
use App\Entity\Tranche;
use App\Entity\Assureur;
use App\Entity\Cotation;
use App\Entity\Paiement;
use App\Entity\Chargement;
use App\Entity\Entreprise;
use App\Entity\Partenaire;
use App\Entity\TypeRevenu;
use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use App\Entity\ConditionPartage;
use App\Entity\RevenuPourCourtier;
use App\Entity\NotificationSinistre;
use App\Repository\CotationRepository;
use App\Entity\OffreIndemnisationSinistre;
use Symfony\Bundle\SecurityBundle\Security;

class CalculationProvider
{
    /**
     * @param ServiceDates $serviceDates
     */
    public function __construct(
        private ServiceDates $serviceDates,
        private CotationRepository $cotationRepository,
        private Security $security,
        private ServiceTaxes $serviceTaxes
    ) {}

    /**
     * Calcule le délai en jours entre la survenance et la notification d'un sinistre.
     */
    private function getNotificationSinistreDelaiDeclaration(NotificationSinistre $sinistre): string
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
    private function getNotificationSinistreAgeDossier(NotificationSinistre $sinistre): string
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
    private function getNotificationSinistreIndiceCompletude(NotificationSinistre $sinistre): string
    {
        $attendus = count($this->getEntreprise()->getModelePieceSinistres());
        if ($attendus === 0) {
            return '100 %'; // S'il n'y a aucune pièce modèle, le dossier est complet.
        }
        $fournis = count($sinistre->getPieces());
        $pourcentage = ($fournis / $attendus) * 100;
        return round($pourcentage) . ' %';
    }

    private function getEntreprise(): Entreprise
    {
        /** @var Utilisateur $user */
        $user = $this->security->getUser();

        return $user->getConnectedTo();
    }

    /**
     * Calcule le pourcentage payé d'une offre d'indemnisation.
     */
    private function getOffreIndemnisationPourcentagePaye(OffreIndemnisationSinistre $offre): string
    {
        $montantPayable = $offre->getMontantPayable();
        if ($montantPayable == 0 || $montantPayable === null) {
            return '100 %'; // Si rien n'est à payer, c'est considéré comme payé.
        }
        $totalVerse = $this->getOffreIndemnisationCompensationVersee($offre);
        $pourcentage = ($totalVerse / $montantPayable) * 100;
        return round($pourcentage) . ' %';
    }

    /**
     * Calcule le montant total de l'indemnisation convenue pour ce sinistre.
     */
    private function getNotificationSinistreCompensation(NotificationSinistre $sinistre): float
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
    private function getNotificationSinistreCompensationVersee(NotificationSinistre $sinistre): float
    {
        $montant = 0;
        if ($sinistre != null) {
            foreach ($sinistre->getOffreIndemnisationSinistres() as $offre_indemnisation) {
                $montant += $this->getOffreIndemnisationCompensationVersee($offre_indemnisation);
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant restant à payer pour solder complètement ce dossier sinistre.
     */
    private function getNotificationSinistreSoldeAVerser(NotificationSinistre $sinistre): float
    {
        $montant = 0;
        if ($sinistre != null) {
            foreach ($sinistre->getOffreIndemnisationSinistres() as $offre_indemnisation) {
                $montant += $this->getOffreIndemnisationSoldeAVerser($offre_indemnisation);
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant de la franchise qui a été appliquée conformément aux termes de la police.
     */
    private function getNotificationSinistreFranchise(NotificationSinistre $sinistre): float
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
    private function getNotificationSinistreDureeReglement(NotificationSinistre $notification_sinistre): int
    {
        $duree = -1;
        $dateNotfication = $notification_sinistre->getNotifiedAt();
        $dateRgelement = null;
        if ($this->getNotificationSinistreSoldeAVerser($notification_sinistre) == 0) {
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
    private function getNotificationSinistreDateDernierReglement(NotificationSinistre $notification_sinistre): ?\DateTimeInterface
    {
        $dateDernierRgelement = null;
        if ($this->getNotificationSinistreSoldeAVerser($notification_sinistre) == 0) {
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
    private function getOffreIndemnisationCompensationVersee(OffreIndemnisationSinistre $offre_indemnisation): float
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
    private function getOffreIndemnisationSoldeAVerser(OffreIndemnisationSinistre $offre_indemnisation): float
    {
        $montant = 0;
        if ($offre_indemnisation != null) {
            $compensation = 0;
            if ($offre_indemnisation->getNotificationSinistre() != null) {
                $compensation = $offre_indemnisation->getMontantPayable();
            }
            $compensationVersee = $this->getOffreIndemnisationCompensationVersee($offre_indemnisation);
            $montant = $compensation - $compensationVersee;
        }
        return $montant;
    }





    /**
     * RETRO-COMMISSION DUE AU PARTENAIRE
     */
    private function getCotationMontantRetrocommissionsPayableParCourtier(?Cotation $cotation, ?Partenaire $partenaireCible, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($cotation != null) {
            foreach ($cotation->getRevenus() as $revenu) {
                $montant += $this->getRevenuMontantRetrocommissionsPayableParCourtier($revenu, $partenaireCible, $addressedTo);
            }
        }
        return $montant;
    }


    private function getCotationPartenaire(?Cotation $cotation)
    {
        if ($cotation != null) {
            if ($cotation->getPiste() != null) {
                if (count($cotation->getPiste()->getPartenaires()) != 0) {
                    // dd($cotation->getPiste()->getPartenaires()[0]);
                    return $cotation->getPiste()->getPartenaires()[0];
                } else if (count($cotation->getPiste()->getClient()->getPartenaires()) != 0) {
                    return $cotation->getPiste()->getClient()->getPartenaires()[0];
                }
            }
        }
        return null;
    }

    private function isSamePartenaire(?Partenaire $partenaire, ?Partenaire $partenaireCible): bool
    {
        if ($partenaireCible == null) {
            return true;
        } else {
            if ($partenaireCible != $partenaire) {
                return false;
            } else {
                return true;
            }
        }
    }


    private function getRevenuMontantRetrocommissionsPayableParCourtier(?RevenuPourCourtier $revenu, ?Partenaire $partenaireCible, $addressedTo): float
    {
        $retrocom = 0;
        if ($revenu != null) {
            /** @var Cotation $cotation */
            $cotation = $revenu->getCotation();
            if ($cotation != null) {
                if ($partenaireCible != null) {
                    if ($revenu->getTypeRevenu()->isShared() == true) {

                        /** @var Partenaire $partenaire */
                        $partenaire = $this->getCotationPartenaire($cotation);

                        if ($partenaire != null) {
                            //On s'assurer que nous parlons du même partenaire
                            if ($this->isSamePartenaire($partenaire, $partenaireCible)) {
                                //D'abord, on traite les conditions spéciale attachées à la piste
                                $conditionsPartagePiste = $cotation->getPiste()->getConditionsPartageExceptionnelles();
                                if (count($conditionsPartagePiste) != 0) {
                                    $retrocom = $this->applyRevenuConditionsSpeciales($conditionsPartagePiste[0], $revenu, $addressedTo);
                                } else {
                                    $conditionsPartagePartenaire = $partenaire->getConditionPartages();
                                    if (count($conditionsPartagePartenaire) != 0) {
                                        $retrocom = $this->applyRevenuConditionsSpeciales($conditionsPartagePartenaire[0], $revenu, $addressedTo);
                                    } else if ($partenaire->getPart() != 0) {
                                        $assiette = $this->getRevenuMontantPure($revenu, $addressedTo, true);
                                        $retrocom = $assiette * $partenaire->getPart();
                                    }
                                }
                            }
                        }
                        // dd("Montant Retrocom: " . $retrocom);
                    }
                }
            }
        }
        return $retrocom;
    }

    private function getCotationMontantChargementPrime(Cotation $cotation, TypeRevenu $typeRevenu)
    {
        $montantChargementCible = 0;
        if ($cotation != null && $typeRevenu != null) {
            //On doit récupérer le montant ou la valeur de ce composant
            foreach ($cotation->getChargements() as $loading) {
                if ($loading->getType() == $typeRevenu->getTypeChargement()) {
                    $montantChargementCible = $loading->getMontantFlatExceptionel();
                }
            }
        }
        return $montantChargementCible;
    }

    private function getCotationRisque(?Cotation $cotation)
    {
        if ($cotation) {
            if ($cotation->getPiste()) {
                return $cotation->getPiste()->getRisque();
            }
        }
        return null;
    }

    private function calculateCommissionPure(RevenuPourCourtier $revenu, bool $onlySharable)
    {
        $taxeCourtier = 0;
        $taxeAssureur = false;
        $comNette = 0;
        $isIARD = $this->isIARD($revenu->getCotation());
        $commissionPure = 0;


        if ($onlySharable == true) {
            if ($revenu->getTypeRevenu()->isShared() == true) {
                // dd($revenu->getTypeRevenu()->isShared(), $revenu);
                $comNette = $this->getRevenuMontantHt($revenu);
                $taxeCourtier = $this->serviceTaxes->getMontantTaxe($comNette, $isIARD, $taxeAssureur);
                $commissionPure = $comNette - $taxeCourtier;
            }
        } else {
            $comNette = $this->getRevenuMontantHt($revenu);
            $taxeCourtier = $this->serviceTaxes->getMontantTaxe($comNette, $isIARD, $taxeAssureur);
            $commissionPure = $comNette - $taxeCourtier;
        }
        return $commissionPure;
    }

    private function getRevenuMontantPure(?RevenuPourCourtier $revenu, $addressedTo, bool $onlySharable): float
    {
        if ($addressedTo != -1) {
            if ($revenu->getTypeRevenu()->getRedevable() == $addressedTo) {
                return $this->calculateCommissionPure($revenu, $onlySharable);
            }
            return 0;
        } else {
            return $this->calculateCommissionPure($revenu, $onlySharable);
        }
    }

    private function calculerRetroCommission(?Risque $risque, ?ConditionPartage $conditionPartage, $assiette): float
    {
        $montant = 0;
        $taux = $conditionPartage->getTaux();
        $produitsCible = $conditionPartage->getProduits();

        switch ($conditionPartage->getCritereRisque()) {
            case ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES:
                $canShare = true;
                foreach ($produitsCible as $produitCible) {
                    if ($produitCible == $risque) {
                        //Ketourah / Ketura, je t'aime.
                        // dd("On ne partage pas car " . $risque . " est dans ", $produitsCible);
                        $canShare = false;
                    }
                }
                $montant = $canShare == true ? ($assiette * $taux) : 0;
                break;
            case ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES:
                foreach ($produitsCible as $produitCible) {
                    if ($produitCible == $risque) {
                        // dd("Oui, on partage car " . $risque . " est dans ", $produitsCible);
                        $montant = $assiette * $taux;
                    }
                }
                break;
            case ConditionPartage::CRITERE_PAS_RISQUES_CIBLES:
                //On applique le taux à l'assiette
                $montant = $assiette * $taux;
                break;

            default:
                # code...
                break;
        }
        return $montant;
    }

    private function getRevenuMontantHtAddressedTo($addressedTo, RevenuPourCourtier $revenu)
    {
        $montant = 0;
        if ($addressedTo != -1) {
            if ($revenu->getTypeRevenu()->getRedevable() == $addressedTo) {
                $montant += $this->getRevenuMontantHt($revenu);
            }
        } else {
            $montant += $this->getRevenuMontantHt($revenu);
        }
        return $montant;
    }


    private function getCotationMontantCommissionHt(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($cotation) {
            //Pour chaque revenu configuré dans cette cotation
            foreach ($cotation->getRevenus() as $revenu) {
                if ($onlySharable == true) {
                    if ($revenu->getTypeRevenu()->isShared() == $onlySharable) {
                        $montant += $this->getRevenuMontantHtAddressedTo($addressedTo, $revenu);
                    }
                } else {
                    $montant += $this->getRevenuMontantHtAddressedTo($addressedTo, $revenu);
                }
            }
        }
        return $montant;
    }
    private function getCotationMontantCommissionPure(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $comHT = $this->getCotationMontantCommissionHt($cotation, $addressedTo, $onlySharable);
        $taxeCourtier = $this->getCotationMontantTaxePayableParCourtier($cotation, $onlySharable);
        return $comHT - $taxeCourtier;
    }

    private function getCotationMontantTaxePayableParCourtier(?Cotation $cotation, bool $onlySharable): float
    {
        return $this->getTotalNet($cotation, $onlySharable, false);
    }

    private function getTotalNet(Cotation $cotation, bool $onlySharable, bool $isTaxAssureur)
    {
        $isIARD = $this->isIARD($cotation);
        $net_payable_par_assureur = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_ASSUREUR, $onlySharable);
        $net_payable_par_client = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_CLIENT, $onlySharable);
        $net_total = $net_payable_par_assureur + $net_payable_par_client;
        return $this->serviceTaxes->getMontantTaxe($net_total, $isIARD, $isTaxAssureur);
    }

    private function getCotationSommeCommissionPureRisque(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $somme = 0;
        /** @var Entreprise $entreprise */
        $entreprise = $cotation->getPiste()->getInvite()->getEntreprise();
        $cotationsDuPartenaire = $this->cotationRepository->loadCotationsWithPartnerRisque($cotation->getPiste()->getExercice(), $entreprise, $cotation->getPiste()->getRisque(), $this->getCotationPartenaire($cotation));
        foreach ($cotationsDuPartenaire as $proposition) {
            $somme += $this->getCotationMontantCommissionPure($proposition, $addressedTo, $onlySharable);
        }
        return $somme;
    }

    private function getCotationSommeCommissionPureClient(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        // dd($cotation->getAvenants()[0]);
        // dd("Unité de mésure: ", $cotation);

        $somme = 0;
        /** @var Entreprise $entreprise */
        $entreprise = $cotation->getPiste()->getInvite()->getEntreprise();
        $cotationsDuPartenaire = $this->cotationRepository->loadCotationsWithPartnerClient($cotation->getPiste()->getExercice(), $entreprise, $cotation->getPiste()->getClient(), $this->getCotationPartenaire($cotation));
        foreach ($cotationsDuPartenaire as $proposition) {
            $somme += $this->getCotationMontantCommissionPure($proposition, $addressedTo, $onlySharable);
        }
        return $somme;
    }

    private function Cotation_getSommeCommissionPurePartenaire(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $somme = 0;
        /** @var Entreprise $entreprise */
        $entreprise = $cotation->getPiste()->getInvite()->getEntreprise();
        $cotationsDuPartenaire = $this->cotationRepository->loadCotationsWithPartnerAll($cotation->getPiste()->getExercice(), $entreprise, $this->getCotationPartenaire($cotation));
        foreach ($cotationsDuPartenaire as $proposition) {
            $somme += $this->getCotationMontantCommissionPure($proposition, $addressedTo, $onlySharable);
        }
        return $somme;
    }

    private function applyRevenuConditionsSpeciales(?ConditionPartage $conditionPartage, RevenuPourCourtier $revenu, $addressedTo): float
    {
        $montant = 0;
        //Assiette de l'affaire individuelle
        $assiette = $this->getRevenuMontantPure($revenu, $addressedTo, true);
        // dd("Je suis ici ", $assiette_commission_pure);

        //Application de l'unité de mésure
        $uniteMesure = match ($conditionPartage->getUniteMesure()) {
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE => $this->getCotationSommeCommissionPureRisque($revenu->getCotation(), $addressedTo, true),
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT => $this->getCotationSommeCommissionPureClient($revenu->getCotation(), $addressedTo, true),
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE => $this->getCotationSommeCommissionPurePartenaire($revenu->getCotation(), $addressedTo, true),
        };

        // dd("Unité de mésure: " . $uniteMesure);

        $formule = $conditionPartage->getFormule();
        $seuil = $conditionPartage->getSeuil();
        $risque = $revenu->getCotation()->getPiste()->getRisque();
        //formule
        switch ($formule) {
            case ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL:
                // dd("ici");
                return $this->calculateRetroCommission($risque, $conditionPartage, $assiette);
                break;
            case ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL:
                if ($uniteMesure < $seuil) {
                    // dd("On partage car l'assiette de " . $assiette_commission_pure . " est inférieur au seuil de " . $seuil);
                    return $this->calculateRetroCommission($risque, $conditionPartage, $assiette);
                } else {
                    // dd("La condition n'est pas respectée ", "Assiette:" . $assiette_commission_pure, "Seuil:" . $seuil);
                    return 0;
                }
                break;
            case ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL:
                // dd("Ici ", $montant, $uniteMesure, $seuil);
                if ($uniteMesure >= $seuil) {
                    // dd("On partage car l'assiette de " . $assiette_commission_pure . " est au moins égal (soit supérieur ou égal) au seuil de " . $seuil);
                    return $this->calculateRetroCommission($risque, $conditionPartage, $assiette);
                } else {
                    // dd("On ne partage pas");
                    return 0;
                }
                break;

            default:
                # code...
                break;
        }
        return $montant;
    }


    /**
     * Calcule le montant total de la prime et de la commission pour une entreprise.
     *
     * @param Entreprise $entreprise L'entreprise de base pour le calcul.
     * @param boolean $isBound Si true, ne calcule que pour les polices souscrites (avec avenant).
     * @param array $options Tableau de filtres optionnels. Peut contenir : 'pisteCible', 'cotationCible', 'assureurCible', 'risqueCible', 'partenaireCible', 'inviteCible', 'groupeCible', 'avenantCible', 'clientCible', 'trancheCible', 'brancheCible', 'reper' ('deteEffet' ou 'echeance'), 'entre', 'et', 'typeRevenuCible', 'revenuPourCourtierCible', 'paiementCible', 'notificationSinistreCible', 'conditionPartageCible'.
     * @return array Un tableau associatif avec 'prime_totale' and 'commission_totale'.
     */
    public function getIndicateursGlobaux(Entreprise $entreprise, bool $isBound, array $options = []): array
    {
        $prime_totale = 0;
        $prime_totale_payee = 0;
        $commission_totale = 0;
        $commission_totale_encaissee = 0;
        $commission_nette = 0;
        $commission_pure = 0;
        $prime_nette = 0;
        $commission_partageable = 0;
        $reserve = 0;
        $retro_commission_partenaire = 0;
        $retro_commission_partenaire_payee = 0;
        $taxe_courtier = 0;
        $taxe_courtier_payee = 0;
        $taxe_assureur = 0;
        $taxe_assureur_payee = 0;
        $sinistre_payable = 0;
        $sinistre_paye = 0;

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
        /** @var TypeRevenu|null $typeRevenuCible */
        $typeRevenuCible = $options['typeRevenuCible'] ?? null;
        /** @var RevenuPourCourtier|null $revenuPourCourtierCible */
        $revenuPourCourtierCible = $options['revenuPourCourtierCible'] ?? null;
        /** @var Paiement|null $paiementCible */
        $paiementCible = $options['paiementCible'] ?? null;
        /** @var NotificationSinistre|null $notificationSinistreCible */
        $notificationSinistreCible = $options['notificationSinistreCible'] ?? null;
        /** @var ConditionPartage|null $conditionPartageCible */
        $conditionPartageCible = $options['conditionPartageCible'] ?? null;

        // 2. Get initial pool of all cotations from the Entreprise
        $cotationsAcalculer = [];
        foreach ($entreprise->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                foreach ($piste->getCotations() as $cotation) {
                    $cotationsAcalculer[] = $cotation;
                }
            }
        }

        // Get initial pool of all claims from the Entreprise
        $sinistresAcalculer = [];
        foreach ($entreprise->getInvites() as $invite) {
            foreach ($invite->getNotificationSinistres() as $sinistre) {
                $sinistresAcalculer[] = $sinistre;
            }
        }

        // 3. Apply filters sequentially
        if ($conditionPartageCible) {
            if ($pisteExceptionnelle = $conditionPartageCible->getPiste()) {
                // This is an exceptional condition for a specific Piste.
                // We only consider cotations from this Piste.
                $cotationsAcalculer = array_filter($cotationsAcalculer, fn(Cotation $cotation) => $cotation->getPiste() === $pisteExceptionnelle);
            } elseif ($partenaireDeLaCondition = $conditionPartageCible->getPartenaire()) {
                // This is a general condition for a Partenaire.
                // First, filter cotations related to this partner.
                $cotationsAcalculer = array_filter($cotationsAcalculer, function (Cotation $cotation) use ($partenaireDeLaCondition) {
                    if (!$cotation->getPiste()) return false;
                    if ($cotation->getPiste()->getPartenaires()->contains($partenaireDeLaCondition)) return true;
                    return $cotation->getPiste()->getClient() && $cotation->getPiste()->getClient()->getPartenaires()->contains($partenaireDeLaCondition);
                });

                // Second, apply the risk criteria from the condition.
                $critereRisque = $conditionPartageCible->getCritereRisque();
                $risquesCibles = $conditionPartageCible->getProduits();

                if ($critereRisque !== ConditionPartage::CRITERE_PAS_RISQUES_CIBLES && !$risquesCibles->isEmpty()) {
                    $idsRisquesCibles = array_map(fn($r) => $r->getId(), $risquesCibles->toArray());

                    $cotationsAcalculer = array_filter($cotationsAcalculer, function (Cotation $cotation) use ($critereRisque, $idsRisquesCibles) {
                        $idRisqueCotation = $cotation->getPiste()?->getRisque()?->getId();
                        if (!$idRisqueCotation) {
                            return false; // Can't apply filter if cotation has no risk.
                        }
                        $dansLaListe = in_array($idRisqueCotation, $idsRisquesCibles);

                        if ($critereRisque === ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES) {
                            return $dansLaListe;
                        }

                        if ($critereRisque === ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES) {
                            return !$dansLaListe;
                        }
                        return true;
                    });
                }
            }
        }
        if ($avenantCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, fn(Cotation $cotation) => $cotation->getAvenants()->contains($avenantCible));
        }
        if ($cotationCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, fn(Cotation $cotation) => $cotation === $cotationCible);
        }
        if ($revenuPourCourtierCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, fn(Cotation $cotation) => $cotation === $revenuPourCourtierCible->getCotation());
        }
        if ($typeRevenuCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, function (Cotation $cotation) use ($typeRevenuCible) {
                foreach ($cotation->getRevenus() as $revenu) {
                    if ($revenu->getTypeRevenu() === $typeRevenuCible) {
                        return true;
                    }
                }
                return false;
            });
        }
        if ($trancheCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, fn(Cotation $cotation) => $trancheCible->getCotation() === $cotation);
        }
        if ($pisteCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, fn(Cotation $cotation) => $cotation->getPiste() === $pisteCible);
        }
        if ($assureurCible) {
            $cotationsAcalculer = array_filter($cotationsAcalculer, fn(Cotation $cotation) => $cotation->getAssureur() === $assureurCible);
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
                $cotationsAcalculer = array_filter($cotationsAcalculer, fn(Cotation $cotation) => $cotation->getPiste() && $cotation->getPiste()->getRisque() && $cotation->getPiste()->getRisque()->getBranche() === $brancheCode);
            }
        }
        if ($notificationSinistreCible) {
            $sinistresAcalculer = array_filter($sinistresAcalculer, fn($s) => $s === $notificationSinistreCible);
            $refPolice = $notificationSinistreCible->getReferencePolice();
            if ($refPolice) {
                $cotationsAcalculer = array_filter($cotationsAcalculer, function (Cotation $cotation) use ($refPolice) {
                    foreach ($cotation->getAvenants() as $avenant) {
                        if ($avenant->getReferencePolice() === $refPolice) return true;
                    }
                    return false;
                });
            } else {
                $cotationsAcalculer = [];
            }
        }
        if ($paiementCible) {
            if ($note = $paiementCible->getNote()) {
                $idsCotationsDeLaNote = [];
                foreach ($note->getArticles() as $article) {
                    if ($cotation = $article->getTranche()?->getCotation()) {
                        $idsCotationsDeLaNote[$cotation->getId()] = true;
                    }
                }
                $cotationsAcalculer = array_filter($cotationsAcalculer, fn($c) => isset($idsCotationsDeLaNote[$c->getId()]));
                $sinistresAcalculer = []; // A payment on a note is not for a claim
            } elseif ($offre = $paiementCible->getOffreIndemnisationSinistre()) {
                if ($sinistreDuPaiement = $offre->getNotificationSinistre()) {
                    $sinistresAcalculer = array_filter($sinistresAcalculer, fn($s) => $s === $sinistreDuPaiement);
                }
                $cotationsAcalculer = []; // A payment on a claim offer doesn't filter cotations for now
            } else {
                $cotationsAcalculer = [];
                $sinistresAcalculer = [];
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
            if ($isBound && !$this->isCotationBound($cotation)) {
                continue; // On saute les cotations non-souscrites si isBound est true.
            }

            $isIARD = $this->isIARD($cotation);

            // Prime Nette
            $prime_nette += $this->getCotationMontantPrimeNette($cotation);

            // Prime
            $prime_cotation = $this->getCotationMontantPrimePayableParClient($cotation);
            $prime_totale += $prime_cotation;
            // La logique de facturation des primes n'étant pas clairement définie via les Articles,
            // le calcul du montant payé ne peut être implémenté de manière fiable pour le moment.
            // $prime_totale_payee += $this->getCotationMontantPrimePayableParClientPayee($cotation);

            // Commission Totale (TTC)
            $commission_ttc_cotation = $this->getCotationMontantCommissionTtc($cotation, -1, false);
            $commission_totale += $commission_ttc_cotation;
            $commission_totale_encaissee += $this->getCotationMontantCommissionEncaissee($cotation);

            // Commission Nette (HT)
            $cotation_com_nette = $this->getCotationMontantCommissionHt($cotation, -1, false);
            $commission_nette += $cotation_com_nette;

            // Taxes
            $cotation_taxe_courtier = $this->getCotationMontantTaxeCourtier($cotation, false);
            $cotation_taxe_assureur = $this->getCotationMontantTaxeAssureur($cotation, false);
            $taxe_courtier += $cotation_taxe_courtier;
            $taxe_assureur += $cotation_taxe_assureur;
            $taxe_courtier_payee += $this->getCotationMontantTaxeCourtierPayee($cotation);
            $taxe_assureur_payee += $this->getCotationMontantTaxeAssureurPayee($cotation);

            // Commission Pure
            $commission_pure += $cotation_com_nette - $cotation_taxe_courtier;

            // Assiette partageable (Commission Pure sur revenus partageables)
            $cotation_com_nette_partageable = $this->getCotationMontantCommissionHt($cotation, -1, true);
            $cotation_taxe_courtier_partageable = $this->getCotationMontantTaxeCourtier($cotation, true);
            $commission_partageable += $cotation_com_nette_partageable - $cotation_taxe_courtier_partageable;

            // Rétro-commissions (Logique complexe conservée dans Constante pour le moment)
            $retro_commission_partenaire += $this->getCotationMontantRetrocommissionsPayableParCourtier($cotation, $partenaireCible, -1, true);
            $retro_commission_partenaire_payee += $this->getCotationMontantRetrocommissionsPayableParCourtierPayee($cotation, $partenaireCible);
        }

        // Calculate claim totals
        foreach ($sinistresAcalculer as $sinistre) {
            $sinistre_payable += $this->getNotificationSinistreCompensation($sinistre);
            $sinistre_paye += $this->getNotificationSinistreCompensationVersee($sinistre);
        }

        // 5. Apply tranche percentage if provided
        if ($trancheCible) {
            $pourcentage = $trancheCible->getPourcentage();
            if ($pourcentage !== null) {
                $prime_totale *= $pourcentage;
                $commission_totale *= $pourcentage;
                $commission_nette *= $pourcentage;
                $commission_pure *= $pourcentage;
                $commission_partageable *= $pourcentage;
                $prime_nette *= $pourcentage;
                $retro_commission_partenaire *= $pourcentage;
                $reserve *= $pourcentage;
                // Les montants payés ne sont pas affectés par le pourcentage de la tranche dans ce contexte.
                $taxe_courtier *= $pourcentage;
                $taxe_assureur *= $pourcentage;
            }
        }

        // 6. Final calculations
        $reserve = $commission_pure - $retro_commission_partenaire;
        $prime_totale_solde = $prime_totale - $prime_totale_payee;
        $commission_totale_solde = $commission_totale - $commission_totale_encaissee;
        $retro_commission_partenaire_solde = $retro_commission_partenaire - $retro_commission_partenaire_payee;
        $taxe_courtier_solde = $taxe_courtier - $taxe_courtier_payee;
        $taxe_assureur_solde = $taxe_assureur - $taxe_assureur_payee;
        $sinistre_solde = $sinistre_payable - $sinistre_paye;
        $taux_sinistralite = ($prime_totale > 0) ? ($sinistre_payable / $prime_totale) * 100 : 0;
        $taux_de_commission = ($prime_nette > 0) ? ($commission_nette / $prime_nette) * 100 : 0;
        $taux_de_retrocommission_effectif = ($commission_partageable > 0) ? ($retro_commission_partenaire / $commission_partageable) * 100 : 0;
        $taux_de_paiement_prime = ($prime_totale > 0) ? ($prime_totale_payee / $prime_totale) * 100 : 0;
        $taux_de_paiement_commission = ($commission_totale > 0) ? ($commission_totale_encaissee / $commission_totale) * 100 : 0;
        $taux_de_paiement_retro_commission = ($retro_commission_partenaire > 0) ? ($retro_commission_partenaire_payee / $retro_commission_partenaire) * 100 : 0;
        $taux_de_paiement_taxe_courtier = ($taxe_courtier > 0) ? ($taxe_courtier_payee / $taxe_courtier) * 100 : 0;
        $taux_de_paiement_taxe_assureur = ($taxe_assureur > 0) ? ($taxe_assureur_payee / $taxe_assureur) * 100 : 0;
        $taux_de_paiement_sinistre = ($sinistre_payable > 0) ? ($sinistre_paye / $sinistre_payable) * 100 : 0;


        return [
            'prime_totale' => $prime_totale,
            'prime_totale_payee' => $prime_totale_payee,
            'prime_totale_solde' => $prime_totale_solde,
            'commission_totale' => $commission_totale,
            'commission_totale_encaissee' => $commission_totale_encaissee,
            'commission_totale_solde' => $commission_totale_solde,
            'commission_nette' => $commission_nette,
            'commission_pure' => $commission_pure,
            'commission_partageable' => $commission_partageable,
            'prime_nette' => $prime_nette,
            'reserve' => $reserve,
            'retro_commission_partenaire' => $retro_commission_partenaire,
            'retro_commission_partenaire_payee' => $retro_commission_partenaire_payee,
            'retro_commission_partenaire_solde' => $retro_commission_partenaire_solde,
            'taxe_courtier' => $taxe_courtier,
            'taxe_courtier_payee' => $taxe_courtier_payee,
            'taxe_courtier_solde' => $taxe_courtier_solde,
            'taxe_assureur' => $taxe_assureur,
            'taxe_assureur_payee' => $taxe_assureur_payee,
            'taxe_assureur_solde' => $taxe_assureur_solde,
            'sinistre_payable' => $sinistre_payable,
            'sinistre_paye' => $sinistre_paye,
            'sinistre_solde' => $sinistre_solde,
            'taux_sinistralite' => $taux_sinistralite,
            'taux_de_commission' => $taux_de_commission,
            'taux_de_retrocommission_effectif' => $taux_de_retrocommission_effectif,
            'taux_de_paiement_prime' => $taux_de_paiement_prime,
            'taux_de_paiement_commission' => $taux_de_paiement_commission,
            'taux_de_paiement_retro_commission' => $taux_de_paiement_retro_commission,
            'taux_de_paiement_taxe_courtier' => $taux_de_paiement_taxe_courtier,
            'taux_de_paiement_taxe_assureur' => $taux_de_paiement_taxe_assureur,
            'taux_de_paiement_sinistre' => $taux_de_paiement_sinistre,
        ];
    }

    private function isCotationBound(?Cotation $cotation): bool
    {
        return $cotation && count($cotation->getAvenants()) > 0;
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

    private function getTrancheMontantRetrocommissionsPayableParCourtierPayee(?Tranche $tranche, ?Partenaire $partenaireCible = null): float
    {
        $montant = 0;
        if (count($tranche->getArticles())) {
            //On doit d'abord s'assurer que nous parlons du même partenaire
            // if ($this->isSamePartenaire($tranche->getCotation()->getPiste()->getPartenaires()[0], $partenaireCible)) {
            if ($this->isSamePartenaire($this->getTranchePartenaire($tranche), $partenaireCible)) {
                /** @var Article $article */
                foreach ($tranche->getArticles() as $articleTranche) {

                    /** @var Article $article */
                    $article = $articleTranche;

                    /** @var Note $note */
                    $note = $article->getNote();

                    //Quelle proportion de la note a-t-elle été payée (100%?)
                    $proportionPaiement = $this->getNoteMontantPaye($note) / $this->getNoteMontantPayable($note);
                    // dd("Ici");

                    //Qu'est-ce qu'on a facturé?
                    if ($note->getAddressedTo() == Note::TO_PARTENAIRE) {
                        $montant += $proportionPaiement * $article->getMontant();
                    }
                }
            }
        }
        return $montant;
    }

    private function getNoteMontantPaye(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            // dd("Les paiements: ", $note->getPaiements());
            foreach ($note->getPaiements() as $encaisse) {
                /** @var Paiement $paiement */
                $paiement = $encaisse;
                $montant += $paiement->getMontant();
                // dd("Paiement : ", $paiement);
            }
        }
        return $montant;
    }

    private function getTranchePartenaire(?Tranche $tranche)
    {
        if ($tranche != null) {
            if ($tranche->getCotation() != null) {
                return $this->getCotationPartenaire($tranche->getCotation());
            }
        }
        return null;
    }

    private function getCotationMontantRetrocommissionsPayableParCourtierPayee(?Cotation $cotation, ?Partenaire $partenaireCible): float
    {
        $montant = 0;
        if ($cotation != null) {
            // $partenaire = $cotation->getPiste()->getPartenaires()[0];
            $partenaire = $this->getCotationPartenaire($cotation);


            if ($partenaire) {
                //On doit d'abord s'assurer que nous parlons du même partenaire
                if ($this->isSamePartenaire($partenaire, $partenaireCible)) {
                    /** @var Tranche $tranche */
                    foreach ($cotation->getTranches() as $tranche) {
                        $montant += $this->getTrancheMontantRetrocommissionsPayableParCourtierPayee($tranche, $partenaireCible);
                    }
                }
            }
        }
        // dd($montant, $partenaire, $partenaireCible);
        return $montant;
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

    private function getCotationMontantTaxeCourtier(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, -1, $onlySharable);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), false);
    }

    private function getCotationMontantTaxeAssureur(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, -1, $onlySharable);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
    }

    private function getNoteMontantPayable(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                $montant += $article->getMontant();
            }
        }
        return $montant;
    }

    private function getCotationMontantCommissionEncaissee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->getTrancheMontantCommissionEncaissee($tranche);
            }
        }
        return $montant;
    }

    private function getTrancheMontantCommissionEncaissee(?Tranche $tranche): float
    {
        $montant = 0;
        if ($tranche) {
            foreach ($tranche->getArticles() as $article) {
                $note = $article->getNote();
                if ($note && ($note->getAddressedTo() == \App\Entity\Note::TO_ASSUREUR || $note->getAddressedTo() == \App\Entity\Note::TO_CLIENT)) {
                    $montantPayableNote = $this->getNoteMontantPayable($note);
                    if ($montantPayableNote > 0) {
                        $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                        $montant += $proportionPaiement * $article->getMontant();
                    }
                }
            }
        }
        return $montant;
    }

    private function getCotationMontantTaxeCourtierPayee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->getTrancheMontantTaxePayee($tranche, false);
            }
        }
        return $montant;
    }

    private function getCotationMontantTaxeAssureurPayee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->getTrancheMontantTaxePayee($tranche, true);
            }
        }
        return $montant;
    }

    private function getTrancheMontantTaxePayee(?Tranche $tranche, bool $isTaxeAssureur): float
    {
        // Cette logique est une simplification et suppose que les notes de taxe sont bien identifiées.
        // La logique complète dans Constante.php est plus complexe et dépend des repositories.
        // Pour une implémentation complète, il faudrait répliquer cette logique ici.
        return 0; // Placeholder
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

    private function getCotationMontantPrimeNette(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getChargements() as $chargement) {
                if ($chargement->getType() && $chargement->getType()->getFonction() === Chargement::FONCTION_PRIME_NETTE) {
                    $montant += $chargement->getMontantFlatExceptionel();
                }
            }
        }
        return $montant;
    }
}
