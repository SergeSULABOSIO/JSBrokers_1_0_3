<?php

namespace App\Comptabilite;

use App\Repository\DepenseRepository;
use App\Repository\ReglementTaxeRepository;
use App\Repository\TokenPurchaseRepository;
use App\Services\ServiceTaxesVente;

/**
 * @file Suivi des obligations de TVA de JS Brokers vis-à-vis de l'autorité fiscale.
 * @description Calcule, par exercice et par mois, la TVA COLLECTÉE (sur les ventes
 * encaissées), la TVA DÉDUCTIBLE (sur les dépenses non annulées), la TVA NETTE DUE
 * (collectée − déductible), le montant REVERSÉ (cf. ReglementTaxe) et le SOLDE DÛ
 * (net dû − reversé). Lecture seule, dérivé des mêmes sources que la comptabilité
 * (ServiceTaxesVente) → cohérence garantie avec les documents comptables.
 */
class SuiviFiscalService
{
    private const MOIS_COURTS = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];

    public function __construct(
        private TokenPurchaseRepository $purchaseRepository,
        private DepenseRepository $depenseRepository,
        private ReglementTaxeRepository $reglementRepository,
        private ServiceTaxesVente $taxesVente,
    ) {
    }

    /**
     * Suivi fiscal d'un exercice : lignes mensuelles + totaux.
     *
     * @return array{
     *   lignes: array<int, array{mois:int, libelle:string, collectee:float, deductible:float, netDu:float, reverse:float, solde:float}>,
     *   totaux: array{collectee:float, deductible:float, netDu:float, reverse:float, solde:float}
     * }
     */
    public function suivi(int $annee): array
    {
        // TVA collectée par mois : taxe due sur le CA TTC encaissé du mois. La taxe
        // étant proportionnelle au TTC, l'appliquer au total mensuel = somme par vente.
        $ca = $this->purchaseRepository->seriesParMoisAnnee($annee)['revenue']; // 12 valeurs (jan→déc)
        $deductibleParMois = $this->depenseRepository->tvaDeductibleParMoisAnnee($annee);
        $reverseParMois = $this->reglementRepository->totalParMoisAnnee($annee);

        $lignes = [];
        $totaux = ['collectee' => 0.0, 'deductible' => 0.0, 'netDu' => 0.0, 'reverse' => 0.0, 'solde' => 0.0];

        for ($mois = 1; $mois <= 12; $mois++) {
            $collectee  = round($this->taxesVente->montantTaxes((float) ($ca[$mois - 1] ?? 0.0)), 2);
            $deductible = round($deductibleParMois[$mois] ?? 0.0, 2);
            $netDu      = round($collectee - $deductible, 2);
            $reverse    = round($reverseParMois[$mois] ?? 0.0, 2);
            $solde      = round($netDu - $reverse, 2);

            $lignes[] = [
                'mois'       => $mois,
                'libelle'    => self::MOIS_COURTS[$mois - 1],
                'collectee'  => $collectee,
                'deductible' => $deductible,
                'netDu'      => $netDu,
                'reverse'    => $reverse,
                'solde'      => $solde,
            ];

            $totaux['collectee']  += $collectee;
            $totaux['deductible'] += $deductible;
            $totaux['netDu']      += $netDu;
            $totaux['reverse']    += $reverse;
            $totaux['solde']      += $solde;
        }

        foreach ($totaux as $k => $v) {
            $totaux[$k] = round($v, 2);
        }

        return ['lignes' => $lignes, 'totaux' => $totaux];
    }

    /**
     * Années civiles à proposer dans le sélecteur d'exercice : union des années de
     * ventes, de dépenses et de reversements, plus l'année courante. Décroissant.
     *
     * @return int[]
     */
    public function exercicesDisponibles(): array
    {
        $annees = [(int) date('Y') => true];

        foreach ($this->purchaseRepository->findChronologique() as $vente) {
            if ($vente->getCreatedAt() !== null) {
                $annees[(int) $vente->getCreatedAt()->format('Y')] = true;
            }
        }
        foreach ($this->depenseRepository->findChronologique() as $depense) {
            if ($depense->getDateDepense() !== null) {
                $annees[(int) $depense->getDateDepense()->format('Y')] = true;
            }
        }
        foreach ($this->reglementRepository->anneesDisponibles() as $annee) {
            $annees[$annee] = true;
        }

        $liste = array_keys($annees);
        rsort($liste);

        return $liste;
    }
}
