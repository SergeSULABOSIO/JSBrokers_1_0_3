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
use App\Repository\CotationRepository;
use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use App\Entity\ConditionPartage;
use App\Entity\RevenuPourCourtier;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\NotificationSinistre;
use Symfony\Bundle\SecurityBundle\Security;

class CalculationProvider
{
    /**
     */
    public function __construct(
        private ServiceDates $serviceDates,
        private Security $security,
        private ServiceTaxes $serviceTaxes,
        private CotationRepository $cotationRepository
    ) {}

    /**
     * Calcule le délai en jours entre la survenance et la notification d'un sinistre.
     */
    public function getNotificationSinistreDelaiDeclaration(NotificationSinistre $sinistre): string
    {
        if (!$sinistre->getOccuredAt() || !$sinistre->getNotifiedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($sinistre->getOccuredAt(), $sinistre->getNotifiedAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * Calcule l'âge du dossier sinistre depuis sa création.
     */
    public function getNotificationSinistreAgeDossier(NotificationSinistre $sinistre): string
    {
        if (!$sinistre->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($sinistre->getCreatedAt(), new DateTimeImmutable()) ?? 0;
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
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, OffreIndemnisationSinistre $offre) {
            return $carry + ($offre->getMontantPayable() ?? 0);
        }, 0.0);
    }

    /**
     * Calcule le montant cumulé des paiements déjà effectués pour cette indemnisation.
     */
    private function getNotificationSinistreCompensationVersee(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, OffreIndemnisationSinistre $offre) {
            return $carry + $this->getOffreIndemnisationCompensationVersee($offre);
        }, 0.0);
    }

    /**
     * Calcule le montant restant à payer pour solder complètement ce dossier sinistre.
     */
    private function getNotificationSinistreSoldeAVerser(NotificationSinistre $sinistre): float
    {
        return $this->getNotificationSinistreCompensation($sinistre) - $this->getNotificationSinistreCompensationVersee($sinistre);
    }

    /**
     * Calcule le montant de la franchise qui a été appliquée conformément aux termes de la police.
     */
    public function getNotificationSinistreFranchise(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, OffreIndemnisationSinistre $offre) {
            return $carry + ($offre->getFranchiseAppliquee() ?? 0);
        }, 0.0);
    }


    /**
     * Calcule le montant cumulé des paiements déjà effectués pour cette offre.
     */
    private function getOffreIndemnisationCompensationVersee(OffreIndemnisationSinistre $offre_indemnisation): float
    {
        return array_reduce($offre_indemnisation->getPaiements()->toArray(), function ($carry, Paiement $paiement) {
            return $carry + ($paiement->getMontant() ?? 0);
        }, 0.0);
    }

    /**
     * Calcule le montant restant à payer pour solder cette offre.
     */
    private function getOffreIndemnisationSoldeAVerser(OffreIndemnisationSinistre $offre_indemnisation): float
    {
        $montantPayable = $offre_indemnisation->getMontantPayable() ?? 0.0;
        $compensationVersee = $this->getOffreIndemnisationCompensationVersee($offre_indemnisation);
        return $montantPayable - $compensationVersee;
    }





    /**
     * RETRO-COMMISSION DUE AU PARTENAIRE
     */
    private function getCotationMontantRetrocommissionsPayableParCourtier(?Cotation $cotation, ?Partenaire $partenaireCible, $addressedTo): float
    {
        if (!$cotation) {
            return 0.0;
        }

        $montant = 0.0;
        foreach ($cotation->getRevenus() as $revenu) {
            $montant += $this->getRevenuMontantRetrocommissionsPayableParCourtier($revenu, $partenaireCible, $addressedTo);
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
        // 1. Gardes de protection pour la robustesse et la lisibilité
        if (!$revenu || !$partenaireCible || !$revenu->getTypeRevenu() || !$revenu->getTypeRevenu()->isShared()) {
            return 0.0;
        }

        $cotation = $revenu->getCotation();
        if (!$cotation || !$cotation->getPiste()) {
            return 0.0;
        }

        $partenaireAffaire = $this->getCotationPartenaire($cotation);
        if (!$partenaireAffaire || !$this->isSamePartenaire($partenaireAffaire, $partenaireCible)) {
            return 0.0;
        }

        // 2. Logique de partage hiérarchique
        // Priorité 1 : Conditions exceptionnelles sur la Piste
        $conditionsPartagePiste = $cotation->getPiste()->getConditionsPartageExceptionnelles();
        if (!$conditionsPartagePiste->isEmpty()) {
            return $this->applyRevenuConditionsSpeciales($conditionsPartagePiste->first(), $revenu, $addressedTo);
        }

        // Priorité 2 : Conditions générales sur le Partenaire
        $conditionsPartagePartenaire = $partenaireAffaire->getConditionPartages();
        if (!$conditionsPartagePartenaire->isEmpty()) {
            return $this->applyRevenuConditionsSpeciales($conditionsPartagePartenaire->first(), $revenu, $addressedTo);
        }

        // Priorité 3 : Taux par défaut du partenaire
        if ($partenaireAffaire->getPart() > 0) {
            $assiette = $this->getRevenuMontantPure($revenu, $addressedTo, true);
            return $assiette * $partenaireAffaire->getPart();
        }

        return 0.0;
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
        if (!$conditionPartage || !$risque) {
            return 0.0;
        }

        $taux = $conditionPartage->getTaux();
        $produitsCible = $conditionPartage->getProduits();

        switch ($conditionPartage->getCritereRisque()) {
            case ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES:
                if (!$produitsCible->contains($risque)) {
                    return $assiette * $taux;
                }
                return 0.0;

            case ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES:
                if ($produitsCible->contains($risque)) {
                    return $assiette * $taux;
                }
                return 0.0;

            case ConditionPartage::CRITERE_PAS_RISQUES_CIBLES:
                return $assiette * $taux;
        }
        return 0.0;
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

    /**
     * Calcule le montant de la taxe courtier pour une cotation.
     *
     * @param Cotation|null $cotation
     * @param boolean $onlySharable
     * @return float
     */
    private function getCotationMontantTaxePayableParCourtier(?Cotation $cotation, bool $onlySharable): float
    {
        return $this->getTotalNet($cotation, $onlySharable, false);
    }

    private function getTotalNet(?Cotation $cotation, bool $onlySharable, bool $isTaxAssureur): float
    {
        if (!$cotation) return 0.0;
        $isIARD = $this->isIARD($cotation);
        $net_payable_par_assureur = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_ASSUREUR, $onlySharable);
        $net_payable_par_client = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_CLIENT, $onlySharable);
        $net_total = $net_payable_par_assureur + $net_payable_par_client;
        return $this->serviceTaxes->getMontantTaxe($net_total, $isIARD, $isTaxAssureur);
    }

    private function getCotationSommeCommissionPureRisque(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $somme = 0;
        if (!$cotation || !$cotation->getPiste()) {
            return 0.0;
        }

        /** @var Entreprise $entreprise */
        $entreprise = $cotation->getPiste()->getInvite()->getEntreprise();
        $exerciceCible = $cotation->getPiste()->getExercice();
        $risqueCible = $cotation->getPiste()->getRisque();
        $partenaireCible = $this->getCotationPartenaire($cotation);

        $cotationsDuPartenaire = [];
        foreach ($entreprise->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                if ($piste->getExercice() === $exerciceCible && $piste->getRisque() === $risqueCible) {
                    foreach ($piste->getCotations() as $c) {
                        if ($this->getCotationPartenaire($c) === $partenaireCible) {
                            $cotationsDuPartenaire[] = $c;
                        }
                    }
                }
            }
        }

        foreach ($cotationsDuPartenaire as $proposition) {
            $somme += $this->getCotationMontantCommissionPure($proposition, $addressedTo, $onlySharable);
        }
        return $somme;
    }

    private function getCotationSommeCommissionPureClient(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $somme = 0;
        if (!$cotation || !$cotation->getPiste()) {
            return 0.0;
        }

        /** @var Entreprise $entreprise */
        $entreprise = $cotation->getPiste()->getInvite()->getEntreprise();
        $exerciceCible = $cotation->getPiste()->getExercice();
        $clientCible = $cotation->getPiste()->getClient();
        $partenaireCible = $this->getCotationPartenaire($cotation);

        $cotationsDuPartenaire = [];
        foreach ($entreprise->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                if ($piste->getExercice() === $exerciceCible && $piste->getClient() === $clientCible) {
                    foreach ($piste->getCotations() as $c) {
                        if ($this->getCotationPartenaire($c) === $partenaireCible) {
                            $cotationsDuPartenaire[] = $c;
                        }
                    }
                }
            }
        }

        foreach ($cotationsDuPartenaire as $proposition) {
            $somme += $this->getCotationMontantCommissionPure($proposition, $addressedTo, $onlySharable);
        }
        return $somme;
    }

    private function getCotationSommeCommissionPurePartenaire(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $somme = 0;
        if (!$cotation || !$cotation->getPiste()) {
            return 0.0;
        }

        /** @var Entreprise $entreprise */
        $entreprise = $cotation->getPiste()->getInvite()->getEntreprise();
        $exerciceCible = $cotation->getPiste()->getExercice();
        $partenaireCible = $this->getCotationPartenaire($cotation);

        $cotationsDuPartenaire = [];
        foreach ($entreprise->getInvites() as $invite) {
            foreach ($invite->getPistes() as $piste) {
                if ($piste->getExercice() === $exerciceCible) {
                    foreach ($piste->getCotations() as $c) {
                        if ($this->getCotationPartenaire($c) === $partenaireCible) {
                            $cotationsDuPartenaire[] = $c;
                        }
                    }
                }
            }
        }

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

        $formule = $conditionPartage->getFormule();
        $seuil = $conditionPartage->getSeuil();
        $risque = $revenu->getCotation()->getPiste()->getRisque();

        //formule
        switch ($formule) {
            case ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL:
                return $this->calculerRetroCommission($risque, $conditionPartage, $assiette);
            case ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL:
                if ($uniteMesure < $seuil) {
                    return $this->calculerRetroCommission($risque, $conditionPartage, $assiette);
                } else {
                    return 0;
                }
            case ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL:
                if ($uniteMesure >= $seuil) {
                    return $this->calculerRetroCommission($risque, $conditionPartage, $assiette);
                } else {
                    return 0;
                }

            default:
                # code...
                break;
        }
        return $montant;
    }

    public function getIndicateursGlobaux(Entreprise $entreprise, bool $isBound, array $options = []): array
    {
        // Initialisation des variables de totaux
        $totals = array_fill_keys([
            'prime_totale', 'prime_totale_payee', 'commission_totale', 'commission_totale_encaissee',
            'commission_nette', 'commission_pure', 'prime_nette', 'commission_partageable', 'reserve',
            'retro_commission_partenaire', 'retro_commission_partenaire_payee', 'taxe_courtier',
            'taxe_courtier_payee', 'taxe_assureur', 'taxe_assureur_payee', 'sinistre_payable', 'sinistre_paye'
        ], 0.0);
        extract($totals);

        // 1. Extraire les filtres des options
        $pisteCible = $options['pisteCible'] ?? null;
        $cotationCible = $options['cotationCible'] ?? null;
        $assureurCible = $options['assureurCible'] ?? null;
        $risqueCible = $options['risqueCible'] ?? null;
        $partenaireCible = $options['partenaireCible'] ?? null;
        $inviteCible = $options['inviteCible'] ?? null;
        $groupeCible = $options['groupeCible'] ?? null;
        $avenantCible = $options['avenantCible'] ?? null;
        $clientCible = $options['clientCible'] ?? null;
        $trancheCible = $options['trancheCible'] ?? null;
        $brancheCible = $options['brancheCible'] ?? null;
        $reper = $options['reper'] ?? null;
        $dateA_str = $options['entre'] ?? null;
        $dateB_str = $options['et'] ?? null;
        $typeRevenuCible = $options['typeRevenuCible'] ?? null;
        $revenuPourCourtierCible = $options['revenuPourCourtierCible'] ?? null;
        $paiementCible = $options['paiementCible'] ?? null;
        $notificationSinistreCible = $options['notificationSinistreCible'] ?? null;
        $conditionPartageCible = $options['conditionPartageCible'] ?? null;

        // 2. Construire la requête dynamique pour les Cotations
        $qb = $this->cotationRepository->createQueryBuilder('c')
            ->join('c.piste', 'p')
            ->join('p.invite', 'i')
            ->where('i.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise);

        // Appliquer les filtres
        if ($isBound) {
            $qb->andWhere($qb->expr()->gt('SIZE(c.avenants)', 0));

        }
        if ($pisteCible) $qb->andWhere('p = :pisteCible')->setParameter('pisteCible', $pisteCible);
        if ($cotationCible) $qb->andWhere('c = :cotationCible')->setParameter('cotationCible', $cotationCible);
        if ($assureurCible) $qb->andWhere('c.assureur = :assureurCible')->setParameter('assureurCible', $assureurCible);
        if ($risqueCible) $qb->andWhere('p.risque = :risqueCible')->setParameter('risqueCible', $risqueCible);
        if ($inviteCible) $qb->andWhere('p.invite = :inviteCible')->setParameter('inviteCible', $inviteCible);
        if ($clientCible) $qb->andWhere('p.client = :clientCible')->setParameter('clientCible', $clientCible);
        if ($groupeCible) $qb->join('p.client', 'cl_g')->andWhere('cl_g.groupe = :groupeCible')->setParameter('groupeCible', $groupeCible);
        if ($partenaireCible) $qb->join('p.partenaires', 'pa')->andWhere('pa = :partenaireCible')->setParameter('partenaireCible', $partenaireCible);
        if ($avenantCible) $qb->join('c.avenants', 'av')->andWhere('av = :avenantCible')->setParameter('avenantCible', $avenantCible);
        if ($trancheCible) $qb->join('c.tranches', 't')->andWhere('t = :trancheCible')->setParameter('trancheCible', $trancheCible);
        if ($revenuPourCourtierCible) $qb->join('c.revenus', 'rpc')->andWhere('rpc = :revenuPourCourtierCible')->setParameter('revenuPourCourtierCible', $revenuPourCourtierCible);
        if ($typeRevenuCible) $qb->join('c.revenus', 'rpc_tr')->andWhere('rpc_tr.typeRevenu = :typeRevenuCible')->setParameter('typeRevenuCible', $typeRevenuCible);

        if ($brancheCible) {
            $brancheCode = ($brancheCible === 'IARD') ? Risque::BRANCHE_IARD_OU_NON_VIE : (($brancheCible === 'VIE') ? Risque::BRANCHE_VIE : -1);
            if ($brancheCode !== -1) {
                $qb->join('p.risque', 'r_b')->andWhere('r_b.branche = :brancheCode')->setParameter('brancheCode', $brancheCode);
            }
        }

        if ($conditionPartageCible) {
            $qb->join('p.conditionsPartageExceptionnelles', 'cp')->andWhere('cp = :conditionPartageCible')->setParameter('conditionPartageCible', $conditionPartageCible);
        }

        if ($reper && $dateA_str && $dateB_str) {
            $dateA = DateTimeImmutable::createFromFormat('d/m/Y', $dateA_str);
            $dateB = DateTimeImmutable::createFromFormat('d/m/Y', $dateB_str);
            if ($dateA && $dateB) {
                $qb->join('c.avenants', 'av_date')
                   ->andWhere($qb->expr()->between(($reper === 'dateEffet' ? 'av_date.startingAt' : 'av_date.endingAt'), ':dateA', ':dateB'))
                   ->setParameter('dateA', $dateA->setTime(0, 0, 0))
                   ->setParameter('dateB', $dateB->setTime(23, 59, 59));
            }
        }

        if ($notificationSinistreCible && $notificationSinistreCible->getReferencePolice()) {
            $qb->join('c.avenants', 'av_sin')->andWhere('av_sin.referencePolice = :refPolice')->setParameter('refPolice', $notificationSinistreCible->getReferencePolice());
        }

        if ($paiementCible) {
            if ($note = $paiementCible->getNote()) {
                $subQuery = $this->cotationRepository->createQueryBuilder('c_sub')
                    ->select('c_sub.id')->join('c_sub.tranches', 't_sub')->join('t_sub.articles', 'a_sub')
                    ->where('a_sub.note = :note')->getDQL();
                $qb->andWhere($qb->expr()->in('c.id', $subQuery))->setParameter('note', $note);
            } else {
                $qb->andWhere('1=0');
            }
        }

        // 3. Exécuter la requête pour obtenir les cotations filtrées
        $cotationsAcalculer = $qb->getQuery()->getResult();

        // Récupérer et filtrer les sinistres (logique non optimisée pour l'instant)
        $sinistresAcalculer = [];
        foreach ($entreprise->getInvites() as $invite) {
            foreach ($invite->getNotificationSinistres() as $sinistre) {
                $sinistresAcalculer[] = $sinistre;
            }
        }
        if ($notificationSinistreCible) {
            $sinistresAcalculer = array_filter($sinistresAcalculer, fn($s) => $s === $notificationSinistreCible);
        }
        if ($paiementCible) {
            if ($offre = $paiementCible->getOffreIndemnisationSinistre()) {
                if ($sinistreDuPaiement = $offre->getNotificationSinistre()) {
                    $sinistresAcalculer = array_filter($sinistresAcalculer, fn($s) => $s === $sinistreDuPaiement);
                }
            } else {
                $sinistresAcalculer = [];
            }
        }

        // 4. Calculate totals from the filtered list
        foreach ($cotationsAcalculer as $cotation) {
            if ($isBound && !$this->isCotationBound($cotation)) {
                continue; // On saute les cotations non-souscrites si isBound est true.
            }

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
            $retro_commission_partenaire += $this->getCotationMontantRetrocommissionsPayableParCourtier($cotation, $partenaireCible, -1);
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

    /**
     * Calcule le montant de la prime nette pour une cotation.
     *
     * @param Cotation|null $cotation
     * @return float
     */
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

    /**
     * Calcule le montant de la commission TTC payable par l'assureur pour une cotation.
     *
     * @param Cotation|null $cotation
     * @param boolean $onlySharable
     * @return float
     */
    private function getCotationMontantCommissionTtcPayableParAssureur(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_ASSUREUR, $onlySharable);
        $taxe = $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
        return $net + $taxe;
    }

    /**
     * Calcule le montant de la commission TTC payable par le client pour une cotation.
     *
     * @param Cotation|null $cotation
     * @param boolean $onlySharable
     * @return float
     */
    private function getCotationMontantCommissionTtcPayableParClient(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_CLIENT, $onlySharable);
        $taxe = $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
        return $net + $taxe;
    }

    /**
     * Vérifie si une cotation appartient à la branche IARD.
     *
     * @param Cotation|null $cotation
     * @return boolean
     */
    private function isIARD(?Cotation $cotation): bool
    {
        if ($cotation && $cotation->getPiste() && $cotation->getPiste()->getRisque()) {
            return $cotation->getPiste()->getRisque()->getBranche() == Risque::BRANCHE_IARD_OU_NON_VIE;
        }
        return false;
    }

    /**
     * Calcule le montant de la taxe courtier pour une cotation.
     *
     * @param Cotation|null $cotation
     * @param boolean $onlySharable
     * @return float
     */
    private function getCotationMontantTaxeCourtier(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, -1, $onlySharable);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), false);
    }

    /**
     * Calcule le montant de la commission TTC pour une cotation.
     *
     * @param Cotation|null $cotation
     * @param integer|null $addressedTo
     * @param boolean $onlySharable
     * @return float
     */
    private function getCotationMontantTaxeAssureur(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, -1, $onlySharable);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
    }

    /**
     * Calcule le montant total payable pour une note.
     *
     * @param Note|null $note
     * @return float
     */
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

    /**
     * Calcule le montant de la commission HT pour une cotation donnée.
     *
     * @param Cotation|null $cotation
     * @param int $addressedTo
     * @param boolean $onlySharable
     * @return float
     */
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

    /**
     * Calcule le montant de la commission encaissée pour une tranche.
     *
     * @param Tranche|null $tranche
     * @return float
     */
    private function getTrancheMontantCommissionEncaissee(?Tranche $tranche): float
    {
        $montant = 0;
        if ($tranche) {
            foreach ($tranche->getArticles() as $article) {
                $note = $article->getNote();
                if ($note && ($note->getAddressedTo() == \App\Entity\Note::TO_ASSUREUR || $note->getAddressedTo() == \App\Entity\Note::TO_CLIENT)) {
                    $montantPayableNote = $this->getNoteMontantPayable($note); // Potentiel bug: division par zéro
                    if ($montantPayableNote > 0) {
                        $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                        $montant += $proportionPaiement * $article->getMontant();
                    }
                }
            }
        }
        return $montant;
    }

    /**
     * Calcule le montant total de la commission encaissée pour une cotation.
     *
     * @param Cotation|null $cotation
     * @return float
     */
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

    /**
     * Calcule le montant de la taxe courtier payée pour une cotation.
     *
     * @param Cotation|null $cotation
     * @return float
     */
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

    /**
     * Calcule le montant de la taxe payée pour une tranche.
     *
     * @param Tranche|null $tranche
     * @param boolean $isTaxeAssureur
     * @return float
     */
    private function getTrancheMontantTaxePayee(?Tranche $tranche, bool $isTaxeAssureur): float
    {
        // Cette logique est une simplification et suppose que les notes de taxe sont bien identifiées.
        // La logique complète dans Constante.php est plus complexe et dépend des repositories.
        // Pour une implémentation complète, il faudrait répliquer cette logique ici.
        return 0.0; // Placeholder
    }

    /**
     * Calcule le montant HT d'un revenu.
     *
     * @param RevenuPourCourtier|null $revenu
     * @return float
     */
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

    /**
     * Calcule le montant de la taxe assureur payée pour une cotation.
     *
     * @param Cotation|null $cotation
     * @return float
     */
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
