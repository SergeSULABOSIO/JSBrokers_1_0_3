<?php

namespace App\Comptabilite;

use App\Entity\Entreprise;

/**
 * @file Suivi des obligations fiscales du COURTIER vis-à-vis des autorités.
 * @description Pendant workspace de SuiviFiscalService (console), dérivé des MÊMES
 * écritures que les documents comptables du courtier (CourtierEcritureComptableService)
 * → cohérence garantie avec le journal, le résultat et la trésorerie. Deux blocs,
 * selon le REDEVABLE de la taxe (règle métier validée) :
 *
 *  - Taxes ASSUREUR (collectées, ex. TVA) : dette fiscale ordinaire, sans impact sur
 *    le résultat. Par mois : COLLECTÉ (crédits 443 des encaissements), DÉDUCTIBLE
 *    (TVA récupérable 445 des dépenses), SOLDE PAYABLE (collecté − déductible),
 *    PAYÉ (reversements D 443) et SOLDE DÛ (payable − payé).
 *
 *  - Taxes COURTIER : CHARGES du cabinet (leurs reversements impactent trésorerie
 *    ET résultat, compte 641). Par mois : DÛ (taxe calculée sur le HT encaissé —
 *    métadonnée taxeCourtierDue des écritures d'encaissement), PAYÉ (reversements
 *    D 641) et SOLDE DÛ (dû − payé).
 *
 * Lecture seule, aucune persistance.
 */
class CourtierSuiviFiscalService
{
    private const MOIS_COURTS = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];

    public function __construct(
        private CourtierEcritureComptableService $ecritures,
    ) {
    }

    /**
     * Suivi fiscal d'un exercice : lignes mensuelles + totaux, par redevable.
     *
     * @return array{
     *   exercice: int,
     *   assureur: array{lignes: array<int, array{mois:int, libelle:string, collectee:float, deductible:float, netDu:float, reverse:float, solde:float}>, totaux: array{collectee:float, deductible:float, netDu:float, reverse:float, solde:float}},
     *   courtier: array{lignes: array<int, array{mois:int, libelle:string, du:float, paye:float, solde:float}>, totaux: array{du:float, paye:float, solde:float}}
     * }
     */
    public function suivi(Entreprise $entreprise, int $exercice): array
    {
        // Agrégats mensuels (index 1..12), dérivés des écritures de l'exercice.
        $collectee = array_fill(1, 12, 0.0);
        $deductible = array_fill(1, 12, 0.0);
        $reverse = array_fill(1, 12, 0.0);
        $duCourtier = array_fill(1, 12, 0.0);
        $payeCourtier = array_fill(1, 12, 0.0);

        foreach ($this->ecritures->ecritures($entreprise) as $e) {
            if ((int) $e['date']->format('Y') !== $exercice) {
                continue;
            }
            $mois = (int) $e['date']->format('n');

            if ($e['type'] === 'encaissement') {
                $duCourtier[$mois] += (float) ($e['taxeCourtierDue'] ?? 0.0);
            }

            foreach ($e['lignes'] as $l) {
                if ($l['compte'] === PlanComptable::TVA_FACTUREE) {
                    if ($e['type'] === 'encaissement') {
                        $collectee[$mois] += $l['credit'];
                    } elseif ($e['type'] === 'reversement_taxe') {
                        $reverse[$mois] += $l['debit'];
                    }
                } elseif ($l['compte'] === PlanComptable::TVA_RECUPERABLE && $e['type'] === 'depense') {
                    $deductible[$mois] += $l['debit'];
                } elseif ($l['compte'] === PlanComptable::IMPOTS_TAXES && $e['type'] === 'reversement_taxe_courtier') {
                    $payeCourtier[$mois] += $l['debit'];
                }
            }
        }

        $lignesAssureur = [];
        $totauxAssureur = ['collectee' => 0.0, 'deductible' => 0.0, 'netDu' => 0.0, 'reverse' => 0.0, 'solde' => 0.0];
        $lignesCourtier = [];
        $totauxCourtier = ['du' => 0.0, 'paye' => 0.0, 'solde' => 0.0];

        for ($mois = 1; $mois <= 12; $mois++) {
            $c  = round($collectee[$mois], 2);
            $d  = round($deductible[$mois], 2);
            $nd = round($c - $d, 2);
            $r  = round($reverse[$mois], 2);
            $s  = round($nd - $r, 2);

            $lignesAssureur[] = [
                'mois'       => $mois,
                'libelle'    => self::MOIS_COURTS[$mois - 1],
                'collectee'  => $c,
                'deductible' => $d,
                'netDu'      => $nd,
                'reverse'    => $r,
                'solde'      => $s,
            ];
            $totauxAssureur['collectee']  += $c;
            $totauxAssureur['deductible'] += $d;
            $totauxAssureur['netDu']      += $nd;
            $totauxAssureur['reverse']    += $r;
            $totauxAssureur['solde']      += $s;

            $du = round($duCourtier[$mois], 2);
            $pa = round($payeCourtier[$mois], 2);
            $so = round($du - $pa, 2);

            $lignesCourtier[] = [
                'mois'    => $mois,
                'libelle' => self::MOIS_COURTS[$mois - 1],
                'du'      => $du,
                'paye'    => $pa,
                'solde'   => $so,
            ];
            $totauxCourtier['du']    += $du;
            $totauxCourtier['paye']  += $pa;
            $totauxCourtier['solde'] += $so;
        }

        foreach ($totauxAssureur as $k => $v) {
            $totauxAssureur[$k] = round($v, 2);
        }
        foreach ($totauxCourtier as $k => $v) {
            $totauxCourtier[$k] = round($v, 2);
        }

        return [
            'exercice' => $exercice,
            'assureur' => ['lignes' => $lignesAssureur, 'totaux' => $totauxAssureur],
            'courtier' => ['lignes' => $lignesCourtier, 'totaux' => $totauxCourtier],
        ];
    }
}
