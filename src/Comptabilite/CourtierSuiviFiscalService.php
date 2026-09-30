<?php

namespace App\Comptabilite;

use App\Entity\Entreprise;
use App\Entity\Taxe;
use App\Repository\TaxeRepository;

/**
 * @file Suivi des obligations fiscales du COURTIER vis-à-vis des autorités.
 * @description Pendant workspace de SuiviFiscalService (console), dérivé des MÊMES
 * écritures que les documents comptables du courtier (CourtierEcritureComptableService)
 * → cohérence garantie avec le journal, le résultat et la trésorerie. Deux blocs,
 * selon le REDEVABLE de la taxe (règle métier validée) :
 *
 *  - Taxes ASSUREUR (collectées, ex. TVA) : dette fiscale ordinaire, sans impact sur
 *    le résultat. Par mois : COLLECTÉ (crédits 443 des FACTURATIONS, moins ce qu'un
 *    avoir annule), DÉDUCTIBLE (TVA récupérable 445 des dépenses), SOLDE PAYABLE
 *    (collecté − déductible), PAYÉ (reversements D 443) et SOLDE DÛ (payable − payé).
 *
 *  - Taxes COURTIER : CHARGES du cabinet (leurs reversements impactent trésorerie
 *    ET résultat, compte 641). Par mois : DÛ (taxe calculée sur le HT FACTURÉ —
 *    métadonnée taxeCourtierDue des écritures d'émission), PAYÉ (reversements
 *    D 641) et SOLDE DÛ (dû − payé).
 *
 * ⚠ LE FAIT GÉNÉRATEUR A CHANGÉ AVEC CELUI DU PRODUIT : la taxe naît de la facture,
 * plus de l'encaissement. Les écritures d'encaissement d'AVANT la bascule restent lues,
 * parce que l'historique n'a pas été repris — mais jamais en double pour une même note.
 *
 * Lecture seule, aucune persistance.
 */
class CourtierSuiviFiscalService
{
    private const MOIS_COURTS = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];

    public function __construct(
        private CourtierEcritureComptableService $ecritures,
        private TaxeRepository $taxeRepository,
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

            // ── LA TAXE SUIT LE PRODUIT ─────────────────────────────────────────
            // Depuis le passage à l'engagement, elle naît à la FACTURATION. Les deux
            // types cohabitent parce que l'historique, lui, est resté sur l'encaissement
            // — mais jamais pour une même note : `produitUneCreance()` tranche, et une
            // note validée n'émet plus d'écriture d'encaissement porteuse de taxe.
            if (in_array($e['type'], ['encaissement', 'facturation', 'avoir_emis'], true)) {
                $duCourtier[$mois] += (float) ($e['taxeCourtierDue'] ?? 0.0);
            }

            foreach ($e['lignes'] as $l) {
                if ($l['compte'] === PlanComptable::TVA_FACTUREE) {
                    if (in_array($e['type'], ['encaissement', 'facturation'], true)) {
                        $collectee[$mois] += $l['credit'];
                    } elseif ($e['type'] === 'avoir_emis') {
                        // UN AVOIR RETRANCHE CE QU'IL ANNULE. Jusqu'ici aucun avoir ne
                        // réduisait la taxe collectée : on déclarait un impôt sur un
                        // produit qu'on venait de reprendre.
                        $collectee[$mois] -= $l['debit'];
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
            'taxes'    => $this->taxesParRedevable($entreprise),
        ];
    }

    /**
     * Fiches descriptives des taxes du workspace, groupées par redevable : nom/code,
     * description, taux IARD et VIE, et autorités auprès desquelles le cabinet est
     * assujetti (destinataires des reversements — ex. DGI, ARCA). Affichées en tête
     * de chaque bloc du suivi fiscal pour situer les montants.
     *
     * @return array{assureur: array<int, array>, courtier: array<int, array>}
     */
    private function taxesParRedevable(Entreprise $entreprise): array
    {
        $result = ['assureur' => [], 'courtier' => []];

        foreach ($this->taxeRepository->findBy(['entreprise' => $entreprise]) as $taxe) {
            $autorites = [];
            foreach ($taxe->getAutoriteFiscales() as $autorite) {
                $abreviation = trim((string) $autorite->getAbreviation());
                $autorites[] = $abreviation !== ''
                    ? sprintf('%s — %s', $abreviation, $autorite->getNom())
                    : (string) $autorite->getNom();
            }

            $fiche = [
                'code'        => $taxe->getCode(),
                'description' => $taxe->getDescription(),
                'tauxIARD'    => (float) $taxe->getTauxIARD(),
                'tauxVIE'     => (float) $taxe->getTauxVIE(),
                'autorites'   => $autorites,
            ];

            $cle = $taxe->getRedevable() === Taxe::REDEVABLE_COURTIER ? 'courtier' : 'assureur';
            $result[$cle][] = $fiche;
        }

        return $result;
    }
}
