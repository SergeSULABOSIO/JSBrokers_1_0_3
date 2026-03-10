<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Partenaire;
use App\Entity\Entreprise;
use App\Entity\Cotation;
use App\Entity\Risque;
use App\Entity\ConditionPartage;
use App\Entity\RevenuPourCourtier;
use App\Entity\Tranche;
use App\Entity\Chargement;
use App\Entity\TypeRevenu;
use App\Entity\Note;
use App\Entity\Taxe;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Repository\CotationRepository;
use App\Repository\NotificationSinistreRepository;
use App\Repository\TaxeRepository;
use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use Symfony\Contracts\Translation\TranslatorInterface;
use DateTimeImmutable;

class PartenaireIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
        private CotationRepository $cotationRepository,
        private NotificationSinistreRepository $notificationSinistreRepository,
        private TaxeRepository $taxeRepository,
        private ServiceTaxes $serviceTaxes,
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Partenaire::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Partenaire $entity */
        $stats = $this->getIndicateursGlobaux($entity->getEntreprise(), false, ['partenaireCible' => $entity]);

        return [
            'nombrePistesApportees' => $this->countPartenairePistes($entity),
            'nombreClientsAssocies' => $this->countPartenaireClients($entity),
            'nombrePolicesGenerees' => $this->countPartenairePolices($entity),
            'nombreConditionsPartage' => $this->countPartenaireConditions($entity),
            'partPourcentage' => round(($entity->getPart() ?? 0) * 100, 2),
            'conditionsPartageResume' => $this->getPartenaireConditionsPartageResume($entity),

            // Mapping des stats globales vers les attributs de l'entité
            'primeTotale' => round($stats['prime_totale'], 2),
            'primePayee' => round($stats['prime_totale_payee'], 2),
            'primeSoldeDue' => round($stats['prime_totale_solde'], 2),
            'tauxCommission' => round($stats['taux_de_commission'], 2),
            'montantHT' => round($stats['commission_nette'], 2),
            'montantTTC' => round($stats['commission_totale'], 2),
            'detailCalcul' => "Agrégation portefeuille",
            
            'taxeCourtierMontant' => round($stats['taxe_courtier'], 2),
            'taxeAssureurMontant' => round($stats['taxe_assureur'], 2),
            
            'montant_du' => round($stats['commission_totale'], 2),
            'montant_paye' => round($stats['commission_totale_encaissee'], 2),
            'solde_restant_du' => round($stats['commission_totale_solde'], 2),
            
            'taxeCourtierPayee' => round($stats['taxe_courtier_payee'], 2),
            'taxeCourtierSolde' => round($stats['taxe_courtier_solde'], 2),
            'taxeAssureurPayee' => round($stats['taxe_assureur_payee'], 2),
            'taxeAssureurSolde' => round($stats['taxe_assureur_solde'], 2),
            
            'montantPur' => round($stats['commission_pure'], 2),
            'retroCommission' => round($stats['retro_commission_partenaire'], 2),
            'retroCommissionReversee' => round($stats['retro_commission_partenaire_payee'], 2),
            'retroCommissionSolde' => round($stats['retro_commission_partenaire_solde'], 2),
            'reserve' => round($stats['reserve'], 2),

            // Sinistralité
            'indemnisationDue' => round($stats['sinistre_payable'], 2),
            'indemnisationVersee' => round($stats['sinistre_paye'], 2),
            'indemnisationSolde' => round($stats['sinistre_solde'], 2),
            'tauxSP' => round($stats['taux_sinistralite'], 2),
            'tauxSPInterpretation' => $this->calculationHelper->getInterpretationTauxSP($stats['taux_sinistralite']),
        ];
    }

    // --- Méthodes privées de base déplacées depuis CalculationProvider ---

    private function countPartenairePistes(Partenaire $partenaire): int
    {
        return $partenaire->getPistes()->count();
    }

    private function countPartenaireClients(Partenaire $partenaire): int
    {
        return $partenaire->getClients()->count();
    }

    private function countPartenairePolices(Partenaire $partenaire): int
    {
        $count = 0;
        foreach ($partenaire->getPistes() as $piste) {
            foreach ($piste->getCotations() as $cotation) {
                if (!$cotation->getAvenants()->isEmpty()) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function countPartenaireConditions(Partenaire $partenaire): int
    {
        return $partenaire->getConditionPartages()->count();
    }

    private function getPartenaireConditionsPartageResume(Partenaire $partenaire): string
    {
        $conditions = $partenaire->getConditionPartages();
        if ($conditions->isEmpty()) {
            return "Aucune condition spécifique définie. Le taux par défaut de " . ($partenaire->getPart() * 100) . "% s'applique à l'ensemble du portefeuille.";
        }

        $resume = "Ce partenaire dispose de " . $conditions->count() . " condition(s) spécifique(s) qui modulent le calcul de sa rétro-commission.";
        
        foreach ($conditions as $condition) {
            $resume .= "\n\n• Condition : " . $condition->getNom();
            $resume .= "\n  Règle : " . $this->getConditionPartageDescriptionRegle($condition);
            
            if (!$condition->getProduits()->isEmpty()) {
                $risquesList = [];
                foreach ($condition->getProduits() as $risque) {
                    $risquesList[] = $risque->getNomComplet();
                }
                $resume .= "\n  Risques ciblés : " . implode(', ', $risquesList) . ".";
            }
        }
        return $resume;
    }

    private function getConditionPartageDescriptionRegle(ConditionPartage $condition): string
    {
        $taux = ($condition->getTaux() ?? 0) * 100;
        $formule = $this->ConditionPartage_getFormuleString($condition);
        $critere = $this->ConditionPartage_getCritereRisqueString($condition);
        $nbRisques = $this->countConditionPartageRisquesCibles($condition);

        $description = "Appliquer " . $taux . "%";

        if ($formule !== "Sans seuil") {
            $seuil = $condition->getSeuil() ?? 0;
            $unite = $this->ConditionPartage_getUniteMesureString($condition);
            $description .= " si {$unite} {$formule} {$seuil}";
        }

        if ($critere !== "Aucun risque ciblé") {
            $description .= ", en se basant sur le critère '{$critere}' avec {$nbRisques} risque(s).";
        }

        return $description;
    }

    private function ConditionPartage_getFormuleString(?ConditionPartage $condition): ?string
    {
        if ($condition === null) return null;
        return match ($condition->getFormule()) {
            ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL => "Assiette >= Seuil",
            ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL => "Assiette < Seuil",
            ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL => "Sans seuil",
            default => "Inconnue",
        };
    }

    private function ConditionPartage_getCritereRisqueString(?ConditionPartage $condition): ?string
    {
        if ($condition === null) return null;
        return match ($condition->getCritereRisque()) {
            ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES => "Exclure risques ciblés",
            ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES => "Inclure risques ciblés",
            ConditionPartage::CRITERE_PAS_RISQUES_CIBLES => "Aucun risque ciblé",
            default => "Inconnu",
        };
    }

    private function ConditionPartage_getUniteMesureString(?ConditionPartage $condition): ?string
    {
        if ($condition === null) return null;
        return match ($condition->getUniteMesure()) {
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE => "Com. pure du risque",
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT => "Com. pure du client",
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE => "Com. pure du partenaire",
            default => "Non définie",
        };
    }

    private function countConditionPartageRisquesCibles(ConditionPartage $condition): int
    {
        return $condition->getProduits()->count();
    }

    // --- Arbre complet de dépendances de "getIndicateursGlobaux" ---

    public function getIndicateursGlobaux(Entreprise $entreprise, bool $isBound, array $options = []): array
    {
        $totals = array_fill_keys([
            'prime_totale', 'prime_totale_payee', 'commission_totale', 'commission_totale_encaissee',
            'commission_nette', 'commission_pure', 'prime_nette', 'commission_partageable', 'reserve',
            'retro_commission_partenaire', 'retro_commission_partenaire_payee', 'taxe_courtier',
            'taxe_courtier_payee', 'taxe_assureur', 'taxe_assureur_payee', 'sinistre_payable', 'sinistre_paye'
        ], 0.0);
        extract($totals);

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

        $qb = $this->cotationRepository->createQueryBuilder('c')
            ->join('c.piste', 'p')
            ->join('p.invite', 'i')
            ->leftJoin('c.avenants', 'av')
            ->leftJoin('c.revenus', 'rev')
            ->leftJoin('rev.typeRevenu', 'rt')
            ->leftJoin('c.tranches', 't')
            ->leftJoin('t.articles', 'art')
            ->leftJoin('art.note', 'n')
            ->leftJoin('n.paiements', 'np')
            ->leftJoin('c.chargements', 'ch')
            ->leftJoin('ch.type', 'cht')
            ->leftJoin('p.risque', 'r')
            ->leftJoin('p.client', 'cl')
            ->leftJoin('p.partenaires', 'pa')
            ->leftJoin('cl.partenaires', 'clpa')
            ->addSelect('p', 'i', 'av', 'rev', 'rt', 't', 'art', 'n', 'np', 'ch', 'cht', 'r', 'cl', 'pa', 'clpa')
            ->where('i.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->distinct();

        if ($isBound) {
            $qb->andWhere($qb->expr()->gt('SIZE(c.avenants)', 0));
        }
        if ($pisteCible) $qb->andWhere('p = :pisteCible')->setParameter('pisteCible', $pisteCible);
        if ($cotationCible) $qb->andWhere('c = :cotationCible')->setParameter('cotationCible', $cotationCible);
        if ($assureurCible) {
            if ($assureurCible->getId() === null) {
                $qb->andWhere('1=0');
            } else {
                $qb->andWhere('c.assureur = :assureurCible')->setParameter('assureurCible', $assureurCible);
            }
        }
        if ($risqueCible) {
            if ($risqueCible->getId() === null) {
                $qb->andWhere('1=0');
            } else {
                $qb->andWhere('p.risque = :risqueCible')->setParameter('risqueCible', $risqueCible);
            }
        }
        if ($inviteCible) $qb->andWhere('p.invite = :inviteCible')->setParameter('inviteCible', $inviteCible);
        if ($clientCible) {
            if ($clientCible->getId() === null) {
                $qb->andWhere('1=0');
            } else {
                $qb->andWhere('p.client = :clientCible')->setParameter('clientCible', $clientCible);
            }
        }
        if ($groupeCible) $qb->join('p.client', 'cl_g')->andWhere('cl_g.groupe = :groupeCible')->setParameter('groupeCible', $groupeCible);
        if ($partenaireCible) {
            if ($partenaireCible->getId() === null) {
                $qb->andWhere('1=0');
            } else {
                $qb->andWhere('pa = :partenaireCible OR clpa = :partenaireCible')->setParameter('partenaireCible', $partenaireCible);
            }
        }
        if ($avenantCible) $qb->andWhere('av = :avenantCible')->setParameter('avenantCible', $avenantCible);
        if ($trancheCible) $qb->andWhere('t = :trancheCible')->setParameter('trancheCible', $trancheCible);
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
                $qb->join('c.tranches', 't_payment')->join('t_payment.articles', 'a_payment')
                   ->andWhere('a_payment.note = :payment_note')
                   ->setParameter('payment_note', $note)
                   ->distinct(); 
            } else {
                $qb->andWhere('1=0');
            }
        }

        $cotationsAcalculer = $qb->getQuery()->getResult();

        $policeReferences = [];
        foreach ($cotationsAcalculer as $cotation) {
            if (!$this->isCotationBound($cotation)) {
                continue;
            }
            foreach ($cotation->getAvenants() as $avenant) {
                if ($avenant->getReferencePolice()) {
                    $policeReferences[] = $avenant->getReferencePolice();
                }
            }
        }
        $policeReferences = array_unique($policeReferences);

        $commissionSums = $this->precomputeCommissionSums($entreprise, $options);

        $sinistresQb = $this->notificationSinistreRepository->createQueryBuilder('ns')
            ->join('ns.invite', 'i')
            ->where('i.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise);

        if (!empty($options)) {
            if (!empty($policeReferences)) {
                $sinistresQb->andWhere('ns.referencePolice IN (:policeReferences)')->setParameter('policeReferences', $policeReferences);
            } else {
                $sinistresQb->andWhere('1=0');
            }
        }

        if ($notificationSinistreCible) {
            $sinistresQb->andWhere('ns = :notificationSinistreCible')
                ->setParameter('notificationSinistreCible', $notificationSinistreCible);
        }

        if ($paiementCible) {
            if ($offre = $paiementCible->getOffreIndemnisationSinistre()) {
                if ($sinistreDuPaiement = $offre->getNotificationSinistre()) {
                    $sinistresQb->andWhere('ns = :sinistreDuPaiement')
                        ->setParameter('sinistreDuPaiement', $sinistreDuPaiement);
                } else {
                    $sinistresQb->andWhere('1=0');
                }
            } else {
                $sinistresQb->andWhere('1=0');
            }
        }

        $sinistresAcalculer = $sinistresQb->getQuery()->getResult();

        foreach ($cotationsAcalculer as $cotation) {
            if ($isBound && !$this->isCotationBound($cotation)) {
                continue; 
            }

            $prime_nette += $this->getCotationMontantPrimeNette($cotation);
            $prime_cotation = $this->getCotationMontantPrimePayableParClient($cotation);
            $prime_totale += $prime_cotation;

            $commission_ttc_cotation = $this->getCotationMontantCommissionTtc($cotation, -1, false);
            $commission_totale += $commission_ttc_cotation;
            $commission_totale_encaissee += $this->getCotationMontantCommissionEncaissee($cotation);

            $cotation_com_nette = $this->getCotationMontantCommissionHt($cotation, -1, false);
            $commission_nette += $cotation_com_nette;

            $cotation_taxe_courtier = $this->getCotationMontantTaxeCourtier($cotation, false);
            $cotation_taxe_assureur = $this->getCotationMontantTaxeAssureur($cotation, false);
            $taxe_courtier += $cotation_taxe_courtier;
            $taxe_assureur += $cotation_taxe_assureur;
            $taxe_courtier_payee += $this->getCotationMontantTaxeCourtierPayee($cotation);
            $taxe_assureur_payee += $this->getCotationMontantTaxeAssureurPayee($cotation);

            $commission_pure += $cotation_com_nette - $cotation_taxe_courtier;

            $cotation_com_nette_partageable = $this->getCotationMontantCommissionHt($cotation, -1, true);
            $cotation_taxe_courtier_partageable = $this->getCotationMontantTaxeCourtier($cotation, true);
            $commission_partageable += $cotation_com_nette_partageable - $cotation_taxe_courtier_partageable;

            $retro_commission_partenaire += $this->getCotationMontantRetrocommissionsPayableParCourtier($cotation, $partenaireCible, -1, $commissionSums);
            $retro_commission_partenaire_payee += $this->getCotationMontantRetrocommissionsPayableParCourtierPayee($cotation, $partenaireCible);
        }

        foreach ($sinistresAcalculer as $sinistre) {
            $sinistre_payable += $this->getNotificationSinistreCompensation($sinistre);
            $sinistre_paye += $this->getNotificationSinistreCompensationVersee($sinistre);
        }

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
                $taxe_courtier *= $pourcentage;
                $taxe_assureur *= $pourcentage;
            }
        }

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

    private function precomputeCommissionSums(Entreprise $entreprise, array $options): array
    {
        $exerciceCible = $options['exercice'] ?? null; 
        if (!$exerciceCible) {
            return ['by_risque' => [], 'by_client' => [], 'by_partenaire' => []];
        }

        return ['by_risque' => [], 'by_client' => [], 'by_partenaire' => []];
    }

    private function isCotationBound(?Cotation $cotation): bool
    {
        return $cotation && !$cotation->getAvenants()->isEmpty();
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

    private function getCotationMontantCommissionHt(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($cotation) {
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
                        $montant += $typeRevenu->getMontantflat();
                    }
                }
            }
        }
        return $montant;
    }

    private function getCotationMontantChargementPrime(Cotation $cotation, TypeRevenu $typeRevenu)
    {
        $montantChargementCible = 0;
        if ($cotation != null && $typeRevenu != null) {
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
        if ($cotation && $cotation->getPiste()) {
            return $cotation->getPiste()->getRisque();
        }
        return null;
    }

    private function isIARD(?Cotation $cotation): bool
    {
        if ($cotation && $cotation->getPiste() && $cotation->getPiste()->getRisque()) {
            return $cotation->getPiste()->getRisque()->getBranche() == Risque::BRANCHE_IARD_OU_NON_VIE;
        }
        return false;
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
                if ($note && ($note->getAddressedTo() == Note::TO_ASSUREUR || $note->getAddressedTo() == Note::TO_CLIENT)) {
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

    private function getNoteMontantPaye(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getPaiements() as $encaisse) {
                /** @var Paiement $paiement */
                $paiement = $encaisse;
                $montant += $paiement->getMontant();
            }
        }
        return $montant;
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

    private function getTrancheMontantTaxePayee(?Tranche $tranche, bool $isTaxeAssureur): float
    {
        $montant = 0.0;
        if (!$tranche) {
            return $montant;
        }

        $targetRedevable = $isTaxeAssureur ? Taxe::REDEVABLE_ASSUREUR : Taxe::REDEVABLE_COURTIER;

        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            if ($note && $note->getAddressedTo() === Note::TO_AUTORITE_FISCALE) {
                $taxe = $this->taxeRepository->find($article->getIdPoste());

                if ($taxe && $taxe->getRedevable() === $targetRedevable) {
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

    private function getCotationMontantRetrocommissionsPayableParCourtier(?Cotation $cotation, ?Partenaire $partenaireCible, $addressedTo, array $precomputedSums): float
    {
        if (!$cotation) {
            return 0.0;
        }

        $montant = 0.0;
        foreach ($cotation->getRevenus() as $revenu) {
            $montant += $this->getRevenuMontantRetrocommissionsPayableParCourtier($revenu, $partenaireCible, $addressedTo, $precomputedSums);
        }
        return $montant;
    }

    private function getRevenuMontantRetrocommissionsPayableParCourtier(?RevenuPourCourtier $revenu, ?Partenaire $partenaireCible, $addressedTo, array $precomputedSums): float
    {
        if (!$revenu || !$revenu->getTypeRevenu() || !$revenu->getTypeRevenu()->isShared()) {
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

        $conditionsPartagePiste = $cotation->getPiste()->getConditionsPartageExceptionnelles();
        if (!$conditionsPartagePiste->isEmpty()) {
            foreach ($conditionsPartagePiste as $condition) {
                $montant = $this->applyRevenuConditionsSpeciales($condition, $revenu, $addressedTo, $precomputedSums);
                if ($montant > 0) return $montant;
            }
            return 0.0;
        }

        $conditionsPartagePartenaire = $partenaireAffaire->getConditionPartages();
        if (!$conditionsPartagePartenaire->isEmpty()) {
            foreach ($conditionsPartagePartenaire as $condition) {
                $montant = $this->applyRevenuConditionsSpeciales($condition, $revenu, $addressedTo, $precomputedSums);
                if ($montant > 0) return $montant;
            }
            return 0.0;
        }

        if ($partenaireAffaire->getPart() > 0) {
            $assiette = $this->getRevenuMontantPure($revenu, $addressedTo, true);
            return $assiette * $partenaireAffaire->getPart();
        }

        return 0.0;
    }

    private function getCotationPartenaire(?Cotation $cotation)
    {
        if ($cotation?->getPiste()) {
            if (!$cotation->getPiste()->getPartenaires()->isEmpty()) {
                return $cotation->getPiste()->getPartenaires()->first();
            }

            $client = $cotation->getPiste()->getClient();
            if ($client && !$client->getPartenaires()->isEmpty()) {
                return $client->getPartenaires()->first();
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

    private function applyRevenuConditionsSpeciales(?ConditionPartage $conditionPartage, RevenuPourCourtier $revenu, $addressedTo, array $precomputedSums): float
    {
        $montant = 0;
        $assiette = $this->getRevenuMontantPure($revenu, $addressedTo, true);
        $piste = $revenu->getCotation()->getPiste();
        if (!$piste) return 0.0;

        $uniteMesure = match ($conditionPartage->getUniteMesure()) {
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE => $precomputedSums['by_risque'][$piste->getRisque()->getId()] ?? 0.0,
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT => $precomputedSums['by_client'][$piste->getClient()->getId()] ?? 0.0,
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE => $precomputedSums['by_partenaire'][($this->getCotationPartenaire($revenu->getCotation()))?->getId()] ?? 0.0,
            default => 0.0,
        };

        $formule = $conditionPartage->getFormule();
        $seuil = $conditionPartage->getSeuil();
        $risque = $revenu->getCotation()->getPiste()->getRisque();

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
        }
        return $montant;
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

    private function calculateCommissionPure(RevenuPourCourtier $revenu, bool $onlySharable)
    {
        $taxeCourtier = 0;
        $taxeAssureur = false;
        $comNette = 0;
        $isIARD = $this->isIARD($revenu->getCotation());
        $commissionPure = 0;

        if ($onlySharable == true) {
            if ($revenu->getTypeRevenu()->isShared() == true) {
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

    private function getCotationMontantRetrocommissionsPayableParCourtierPayee(?Cotation $cotation, ?Partenaire $partenaireCible): float
    {
        $montant = 0;
        if ($cotation != null) {
            $partenaire = $this->getCotationPartenaire($cotation);
            if ($partenaire) {
                if ($this->isSamePartenaire($partenaire, $partenaireCible)) {
                    foreach ($cotation->getTranches() as $tranche) {
                        $montant += $this->getTrancheMontantRetrocommissionsPayableParCourtierPayee($tranche, $partenaireCible);
                    }
                }
            }
        }
        return $montant;
    }

    private function getTrancheMontantRetrocommissionsPayableParCourtierPayee(?Tranche $tranche, ?Partenaire $partenaireCible = null): float
    {
        $montant = 0;
        if (!$tranche || $tranche->getArticles()->isEmpty()) {
            return 0.0;
        }

        if ($this->isSamePartenaire($this->getTranchePartenaire($tranche), $partenaireCible)) {
            foreach ($tranche->getArticles() as $article) {
                $note = $article->getNote();
                if (!$note) {
                    continue;
                }

                $montantPayableNote = $this->getNoteMontantPayable($note);
                $proportionPaiement = 0;
                if ($montantPayableNote > 0) {
                    $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                }

                if ($note->getAddressedTo() == Note::TO_PARTENAIRE) {
                    $montant += $proportionPaiement * ($article->getMontant() ?? 0);
                }
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

    private function getNotificationSinistreCompensation(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, OffreIndemnisationSinistre $offre) {
            return $carry + ($offre->getMontantPayable() ?? 0);
        }, 0.0);
    }

    private function getNotificationSinistreCompensationVersee(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, OffreIndemnisationSinistre $offre) {
            return $carry + $this->getOffreIndemnisationCompensationVersee($offre);
        }, 0.0);
    }

    private function getOffreIndemnisationCompensationVersee(OffreIndemnisationSinistre $offre_indemnisation): float
    {
        return array_reduce($offre_indemnisation->getPaiements()->toArray(), function ($carry, Paiement $paiement) {
            return $carry + ($paiement->getMontant() ?? 0);
        }, 0.0);
    }
}