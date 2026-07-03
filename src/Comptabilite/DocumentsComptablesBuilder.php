<?php

namespace App\Comptabilite;

/**
 * @file Moteur GÉNÉRIQUE de construction des documents comptables SYSCOHADA.
 * @description Reçoit une liste d'écritures normalisées — { date, piece, libelle,
 * type, lignes:[{compte, libelle, debit, credit}] } — et en dérive les sept états :
 * journal, grand livre, balance, compte de résultat, TFR, bilan et TFT. Aucune
 * dépendance aux repositories : la COLLECTE des écritures reste la responsabilité
 * des services appelants (EcritureComptableService pour la plateforme JS Brokers,
 * CourtierEcritureComptableService pour l'espace de travail du courtier). Les
 * libellés de comptes sont ceux portés par les lignes d'écritures, ce qui permet
 * à chaque contexte d'employer sa terminologie (« Services vendus » vs
 * « Commissions de courtage ») sans dupliquer le moteur. Sans état, lecture seule.
 */
final class DocumentsComptablesBuilder
{
    /**
     * Les sept documents comptables d'un exercice (année civile), prêts à
     * l'affichage et à l'export.
     *
     * @param array<int, array{date:\DateTimeImmutable, piece:string, libelle:string, type:string, lignes:array<int, array{compte:string, libelle:string, debit:float, credit:float}>}> $ecritures
     *        Toutes les écritures, tous exercices confondus, triées par date croissante.
     * @param array{libelleCA?: string} $options Ajustements de terminologie (1ʳᵉ ligne du TFR).
     *
     * @return array{exercice:int, ouverture:\DateTimeImmutable, cloture:\DateTimeImmutable, journal:array, grandLivre:array, balance:array, resultat:array, tfr:array, bilan:array, tft:array}
     */
    public function documents(array $ecritures, int $exercice, array $options = []): array
    {
        $debut = new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $exercice));
        $fin   = new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $exercice + 1));

        $avant   = array_filter($ecritures, static fn (array $e) => $e['date'] < $debut);
        $periode = array_filter($ecritures, static fn (array $e) => $e['date'] >= $debut && $e['date'] < $fin);

        $aggOuverture = $this->aggreger($avant);
        $aggPeriode   = $this->aggreger($periode);
        $aggCloture   = $this->fusionner($aggOuverture, $aggPeriode);

        // Libellés des comptes tels que portés par les écritures (tous exercices),
        // pour que grand livre et balance restent nommés même sur les à-nouveaux.
        $libelles = $this->libelles($ecritures);

        return [
            'exercice'   => $exercice,
            'ouverture'  => $debut,
            'cloture'    => $fin->modify('-1 day'),
            'journal'    => $this->journal($periode),
            'grandLivre' => $this->grandLivre($periode, $aggOuverture, $libelles),
            'balance'    => $this->balance($aggOuverture, $aggPeriode, $aggCloture, $libelles),
            'resultat'   => $this->resultat($aggPeriode, $libelles),
            'tfr'        => $this->tfr($aggPeriode, $options['libelleCA'] ?? 'Chiffre d\'affaires (produit HT)'),
            'bilan'      => $this->bilan($aggOuverture, $aggCloture, $aggPeriode),
            'tft'        => $this->tft($periode, $aggOuverture, $aggCloture),
        ];
    }

    /**
     * Années civiles présentes dans les écritures (pour le sélecteur d'exercice),
     * de la plus récente à la plus ancienne. À défaut : l'année courante.
     *
     * @return int[]
     */
    public function exercicesDisponibles(array $ecritures): array
    {
        $annees = [];
        foreach ($ecritures as $e) {
            $annees[(int) $e['date']->format('Y')] = true;
        }

        if ($annees === []) {
            return [(int) date('Y')];
        }

        $liste = array_keys($annees);
        rsort($liste);

        return $liste;
    }

    // ===================== Agrégation par compte =====================

    /** Carte compte => libellé, construite depuis les lignes d'écritures. */
    private function libelles(array $ecritures): array
    {
        $libelles = [];
        foreach ($ecritures as $e) {
            foreach ($e['lignes'] as $l) {
                $libelles[$l['compte']] ??= $l['libelle'];
            }
        }

        return $libelles;
    }

    /**
     * Agrège un jeu d'écritures par compte : compte => { libelle, debit, credit }.
     *
     * @param array<int, array> $ecritures
     *
     * @return array<string, array{libelle:string, debit:float, credit:float}>
     */
    private function aggreger(array $ecritures): array
    {
        $agg = [];
        foreach ($ecritures as $e) {
            foreach ($e['lignes'] as $l) {
                $compte = $l['compte'];
                if (!isset($agg[$compte])) {
                    $agg[$compte] = ['libelle' => $l['libelle'], 'debit' => 0.0, 'credit' => 0.0];
                }
                $agg[$compte]['debit']  += $l['debit'];
                $agg[$compte]['credit'] += $l['credit'];
            }
        }

        ksort($agg);

        return $agg;
    }

    /** Fusionne deux agrégats (débit/crédit cumulés) compte par compte. */
    private function fusionner(array $a, array $b): array
    {
        $r = $a;
        foreach ($b as $compte => $v) {
            if (!isset($r[$compte])) {
                $r[$compte] = ['libelle' => $v['libelle'], 'debit' => 0.0, 'credit' => 0.0];
            }
            $r[$compte]['debit']  += $v['debit'];
            $r[$compte]['credit'] += $v['credit'];
        }
        ksort($r);

        return $r;
    }

    /** Solde signé (débiteur positif) d'un compte dans un agrégat. */
    private function solde(array $agg, string $compte): float
    {
        if (!isset($agg[$compte])) {
            return 0.0;
        }

        return round($agg[$compte]['debit'] - $agg[$compte]['credit'], 2);
    }

    /**
     * Solde signé cumulé (débit − crédit) des comptes dont le code commence par le
     * préfixe donné. Un compte à 2 chiffres (console) comme ses subdivisions
     * (« 632 », « 641 » côté courtier) sont ainsi rattachés à leur poste OHADA.
     */
    private function soldePrefixe(array $agg, string $prefixe): float
    {
        $total = 0.0;
        foreach ($agg as $compte => $v) {
            if (str_starts_with($compte, $prefixe)) {
                $total += $v['debit'] - $v['credit'];
            }
        }

        return round($total, 2);
    }

    /** Somme des soldes (débit − crédit) des comptes d'une classe (1er chiffre). */
    private function soldeClasse(array $agg, int $classe): float
    {
        $total = 0.0;
        foreach ($agg as $compte => $v) {
            if (PlanComptable::classe($compte) === $classe) {
                $total += $v['debit'] - $v['credit'];
            }
        }

        return round($total, 2);
    }

    // ===================== Documents =====================

    /**
     * Journal : écritures chronologiques de l'exercice + totaux débit/crédit.
     *
     * @return array{ecritures:array, totalDebit:float, totalCredit:float}
     */
    private function journal(array $periode): array
    {
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $ecritures = array_values($periode);
        foreach ($ecritures as $e) {
            foreach ($e['lignes'] as $l) {
                $totalDebit  += $l['debit'];
                $totalCredit += $l['credit'];
            }
        }

        return [
            'ecritures'   => $ecritures,
            'totalDebit'  => round($totalDebit, 2),
            'totalCredit' => round($totalCredit, 2),
        ];
    }

    /**
     * Grand livre : par compte, le report à-nouveau, les mouvements de l'exercice
     * (avec solde progressif) et le solde de clôture. Comptes triés par code.
     *
     * @return array<int, array{compte:string, libelle:string, aNouveau:float, lignes:array, totalDebit:float, totalCredit:float, solde:float}>
     */
    private function grandLivre(array $periode, array $aggOuverture, array $libelles): array
    {
        // Mouvements de la période regroupés par compte (en conservant l'ordre).
        $parCompte = [];
        foreach ($periode as $e) {
            foreach ($e['lignes'] as $l) {
                $parCompte[$l['compte']][] = [
                    'date'    => $e['date'],
                    'piece'   => $e['piece'],
                    'libelle' => $e['libelle'],
                    'debit'   => $l['debit'],
                    'credit'  => $l['credit'],
                ];
            }
        }

        // Comptes à présenter : ceux avec un à-nouveau OU des mouvements.
        $comptes = array_unique(array_merge(array_keys($aggOuverture), array_keys($parCompte)));
        sort($comptes);

        $resultat = [];
        foreach ($comptes as $compte) {
            $aNouveau = $this->solde($aggOuverture, $compte);
            $lignes = $parCompte[$compte] ?? [];
            if ($aNouveau === 0.0 && $lignes === []) {
                continue;
            }

            $solde = $aNouveau;
            $totalDebit = 0.0;
            $totalCredit = 0.0;
            $lignesAvecSolde = [];
            foreach ($lignes as $l) {
                $solde = round($solde + $l['debit'] - $l['credit'], 2);
                $totalDebit  += $l['debit'];
                $totalCredit += $l['credit'];
                $lignesAvecSolde[] = $l + ['solde' => $solde];
            }

            $resultat[] = [
                'compte'      => $compte,
                'libelle'     => $libelles[$compte] ?? PlanComptable::libelle($compte),
                'aNouveau'    => $aNouveau,
                'lignes'      => $lignesAvecSolde,
                'totalDebit'  => round($totalDebit, 2),
                'totalCredit' => round($totalCredit, 2),
                'solde'       => $solde,
            ];
        }

        return $resultat;
    }

    /**
     * Balance générale à 8 colonnes : par compte, soldes d'ouverture (D/C),
     * mouvements de la période (D/C), soldes de clôture (D/C), + ligne de totaux.
     *
     * @return array{lignes:array, totaux:array}
     */
    private function balance(array $aggOuverture, array $aggPeriode, array $aggCloture, array $libelles): array
    {
        $comptes = array_keys($aggCloture);
        sort($comptes);

        $lignes = [];
        $totaux = ['ouvD' => 0.0, 'ouvC' => 0.0, 'mvtD' => 0.0, 'mvtC' => 0.0, 'cloD' => 0.0, 'cloC' => 0.0];

        foreach ($comptes as $compte) {
            $ouvNet = $this->solde($aggOuverture, $compte);
            $cloNet = $this->solde($aggCloture, $compte);
            $mvtD = isset($aggPeriode[$compte]) ? round($aggPeriode[$compte]['debit'], 2) : 0.0;
            $mvtC = isset($aggPeriode[$compte]) ? round($aggPeriode[$compte]['credit'], 2) : 0.0;

            $ligne = [
                'compte'  => $compte,
                'libelle' => $libelles[$compte] ?? PlanComptable::libelle($compte),
                'ouvD'    => max($ouvNet, 0.0),
                'ouvC'    => max(-$ouvNet, 0.0),
                'mvtD'    => $mvtD,
                'mvtC'    => $mvtC,
                'cloD'    => max($cloNet, 0.0),
                'cloC'    => max(-$cloNet, 0.0),
            ];
            foreach (['ouvD', 'ouvC', 'mvtD', 'mvtC', 'cloD', 'cloC'] as $k) {
                $totaux[$k] += $ligne[$k];
            }
            $lignes[] = $ligne;
        }

        foreach ($totaux as $k => $v) {
            $totaux[$k] = round($v, 2);
        }

        return ['lignes' => $lignes, 'totaux' => $totaux];
    }

    /**
     * Compte de résultat : produits (classe 7) et charges (classe 6) de l'exercice,
     * détaillés par compte, et résultat net.
     *
     * @return array{produits:array, charges:array, totalProduits:float, totalCharges:float, resultat:float}
     */
    private function resultat(array $aggPeriode, array $libelles): array
    {
        $produits = [];
        $charges = [];
        $totalProduits = 0.0;
        $totalCharges = 0.0;

        foreach ($aggPeriode as $compte => $v) {
            $classe = PlanComptable::classe($compte);
            if ($classe === 7) {
                $montant = round($v['credit'] - $v['debit'], 2);
                $produits[] = ['compte' => $compte, 'libelle' => $libelles[$compte] ?? PlanComptable::libelle($compte), 'montant' => $montant];
                $totalProduits += $montant;
            } elseif ($classe === 6) {
                $montant = round($v['debit'] - $v['credit'], 2);
                $charges[] = ['compte' => $compte, 'libelle' => $libelles[$compte] ?? PlanComptable::libelle($compte), 'montant' => $montant];
                $totalCharges += $montant;
            }
        }

        return [
            'produits'      => $produits,
            'charges'       => $charges,
            'totalProduits' => round($totalProduits, 2),
            'totalCharges'  => round($totalCharges, 2),
            'resultat'      => round($totalProduits - $totalCharges, 2),
        ];
    }

    /**
     * Tableau de formation du résultat (soldes intermédiaires de gestion OHADA) :
     * cascade CA → Valeur ajoutée → EBE → Résultat d'exploitation → Résultat
     * financier → Résultat des activités ordinaires → Résultat net. Le résultat net
     * est identique à celui du compte de résultat (même base de comptes). Chaque
     * poste agrège les comptes PAR PRÉFIXE : les subdivisions (« 632 », « 641 »)
     * suivent leur compte principal.
     *
     * @return array<int, array{libelle:string, montant:float, solde:bool}>
     */
    private function tfr(array $aggPeriode, string $libelleCA): array
    {
        // Charge HT d'un poste OHADA classe 6 (solde débiteur de la période, subdivisions incluses).
        $charge = fn (string $prefixe): float => max($this->soldePrefixe($aggPeriode, $prefixe), 0.0);

        $ca             = $this->produitsClasse7($aggPeriode); // produits HT (crédit − débit)
        $consoExternes  = round($charge('60') + $charge('61') + $charge('62') + $charge('63') + $charge('65'), 2);
        $valeurAjoutee  = round($ca - $consoExternes, 2);
        $impotsTaxes    = $charge('64');
        $personnel      = $charge('66');
        $ebe            = round($valeurAjoutee - $impotsTaxes - $personnel, 2);
        $dotations      = round($charge('68') + $charge('69'), 2);
        $resultatExploit = round($ebe - $dotations, 2);
        $fraisFinanciers = $charge('67');
        $resultatFinancier = round(-$fraisFinanciers, 2);
        $rao            = round($resultatExploit + $resultatFinancier, 2);

        return [
            ['libelle' => $libelleCA, 'montant' => $ca, 'solde' => true],
            ['libelle' => 'Consommations externes (60, 61, 62, 63, 65)', 'montant' => -$consoExternes, 'solde' => false],
            ['libelle' => 'Valeur ajoutée', 'montant' => $valeurAjoutee, 'solde' => true],
            ['libelle' => 'Impôts et taxes (64)', 'montant' => -$impotsTaxes, 'solde' => false],
            ['libelle' => 'Charges de personnel (66)', 'montant' => -$personnel, 'solde' => false],
            ['libelle' => 'Excédent brut d\'exploitation (EBE)', 'montant' => $ebe, 'solde' => true],
            ['libelle' => 'Dotations aux amortissements et provisions (68, 69)', 'montant' => -$dotations, 'solde' => false],
            ['libelle' => 'Résultat d\'exploitation', 'montant' => $resultatExploit, 'solde' => true],
            ['libelle' => 'Frais financiers (67)', 'montant' => -$fraisFinanciers, 'solde' => false],
            ['libelle' => 'Résultat financier', 'montant' => $resultatFinancier, 'solde' => true],
            ['libelle' => 'Résultat des activités ordinaires', 'montant' => $rao, 'solde' => true],
            ['libelle' => 'Résultat net', 'montant' => $rao, 'solde' => true],
        ];
    }

    /** Total des produits (classe 7) d'un agrégat : Σ (crédit − débit). */
    private function produitsClasse7(array $agg): float
    {
        $total = 0.0;
        foreach ($agg as $compte => $v) {
            if (PlanComptable::classe($compte) === 7) {
                $total += $v['credit'] - $v['debit'];
            }
        }

        return round($total, 2);
    }

    /**
     * Bilan comparatif (ouverture / clôture de l'exercice) : Actif (trésorerie,
     * TVA récupérable) et Passif (capitaux propres = capital + report + résultat,
     * dettes = fournisseurs + TVA facturée). L'égalité Actif = Passif est garantie
     * par l'équilibre des écritures.
     *
     * @return array{actif:array, passif:array}
     */
    private function bilan(array $aggOuverture, array $aggCloture, array $aggPeriode): array
    {
        // Résultat de l'exercice (mouvements de la période) et report (cumul antérieur).
        $resultatExercice = round($this->produitsClasse7($aggPeriode) - $this->soldeClasse($aggPeriode, 6), 2);

        $colonne = function (array $agg, bool $cloture) use ($resultatExercice): array {
            $tresorerie = round($this->solde($agg, PlanComptable::BANQUES) + $this->solde($agg, PlanComptable::CAISSE), 2);
            $tvaRecup   = $this->solde($agg, PlanComptable::TVA_RECUPERABLE);

            $capital    = -$this->solde($agg, PlanComptable::CAPITAL_SOCIAL); // compte créditeur
            // Résultat cumulé porté en capitaux propres = Σ produits − Σ charges (depuis l'origine).
            $resultatCumule = round($this->produitsClasse7($agg) - $this->soldeClasse($agg, 6), 2);
            // À l'ouverture, l'exercice n'a pas encore couru : tout est en report.
            $resultat = $cloture ? $resultatExercice : 0.0;
            $report   = round($resultatCumule - $resultat, 2);

            $fournisseurs = -$this->solde($agg, PlanComptable::FOURNISSEURS);
            $tvaFacturee  = -$this->solde($agg, PlanComptable::TVA_FACTUREE);
            $tvaDue       = -$this->solde($agg, PlanComptable::TVA_DUE); // TVA liquidée restant à reverser

            return [
                'actif' => [
                    'tresorerie' => $tresorerie,
                    'tvaRecup'   => $tvaRecup,
                    'total'      => round($tresorerie + $tvaRecup, 2),
                ],
                'passif' => [
                    'capital'      => $capital,
                    'report'       => $report,
                    'resultat'     => $resultat,
                    'fournisseurs' => $fournisseurs,
                    'tvaFacturee'  => $tvaFacturee,
                    'tvaDue'       => $tvaDue,
                    'total'        => round($capital + $report + $resultat + $fournisseurs + $tvaFacturee + $tvaDue, 2),
                ],
            ];
        };

        $ouv = $colonne($aggOuverture, false);
        $clo = $colonne($aggCloture, true);

        return [
            'actif' => [
                ['libelle' => 'Trésorerie (banques, caisse)', 'ouverture' => $ouv['actif']['tresorerie'], 'cloture' => $clo['actif']['tresorerie']],
                ['libelle' => 'État, TVA récupérable', 'ouverture' => $ouv['actif']['tvaRecup'], 'cloture' => $clo['actif']['tvaRecup']],
                ['libelle' => 'TOTAL ACTIF', 'ouverture' => $ouv['actif']['total'], 'cloture' => $clo['actif']['total'], 'total' => true],
            ],
            'passif' => [
                ['libelle' => 'Capital social', 'ouverture' => $ouv['passif']['capital'], 'cloture' => $clo['passif']['capital']],
                ['libelle' => 'Report à nouveau', 'ouverture' => $ouv['passif']['report'], 'cloture' => $clo['passif']['report']],
                ['libelle' => 'Résultat de l\'exercice', 'ouverture' => $ouv['passif']['resultat'], 'cloture' => $clo['passif']['resultat']],
                ['libelle' => 'Fournisseurs', 'ouverture' => $ouv['passif']['fournisseurs'], 'cloture' => $clo['passif']['fournisseurs']],
                ['libelle' => 'État, TVA facturée', 'ouverture' => $ouv['passif']['tvaFacturee'], 'cloture' => $clo['passif']['tvaFacturee']],
                ['libelle' => 'État, TVA due', 'ouverture' => $ouv['passif']['tvaDue'], 'cloture' => $clo['passif']['tvaDue']],
                ['libelle' => 'TOTAL PASSIF', 'ouverture' => $ouv['passif']['total'], 'cloture' => $clo['passif']['total'], 'total' => true],
            ],
        ];
    }

    /**
     * Tableau de flux de trésorerie : trésorerie d'ouverture + flux d'exploitation
     * (encaissements − décaissements) + flux de financement (apports en capital)
     * = trésorerie de clôture. Reconcilie avec le bilan. Les flux sont classés par
     * SENS du mouvement de trésorerie (débit = entrée, crédit = sortie), ce qui
     * reste exact quel que soit le vocabulaire de types des écritures sources.
     *
     * @return array{ouverture:float, encaissements:float, decaissements:float, fluxExploitation:float, fluxFinancement:float, variation:float, cloture:float}
     */
    private function tft(array $periode, array $aggOuverture, array $aggCloture): array
    {
        $tresorerieOuv = round($this->solde($aggOuverture, PlanComptable::BANQUES) + $this->solde($aggOuverture, PlanComptable::CAISSE), 2);
        $tresorerieClo = round($this->solde($aggCloture, PlanComptable::BANQUES) + $this->solde($aggCloture, PlanComptable::CAISSE), 2);

        $encaissements = 0.0;
        $decaissements = 0.0;
        $financement = 0.0;
        foreach ($periode as $e) {
            foreach ($e['lignes'] as $l) {
                if (!in_array($l['compte'], PlanComptable::COMPTES_TRESORERIE, true)) {
                    continue;
                }
                if ($e['type'] === 'capital') {
                    $financement += $l['debit'] - $l['credit'];
                } elseif ($l['debit'] >= $l['credit']) {
                    $encaissements += $l['debit'] - $l['credit'];
                } else {
                    $decaissements += $l['credit'] - $l['debit'];
                }
            }
        }

        $fluxExploitation = round($encaissements - $decaissements, 2);
        $fluxFinancement = round($financement, 2);

        return [
            'ouverture'        => $tresorerieOuv,
            'encaissements'    => round($encaissements, 2),
            'decaissements'    => round($decaissements, 2),
            'fluxExploitation' => $fluxExploitation,
            'fluxFinancement'  => $fluxFinancement,
            'variation'        => round($fluxExploitation + $fluxFinancement, 2),
            'cloture'          => $tresorerieClo,
        ];
    }
}
