<?php

namespace App\Comptabilite;

use App\Entity\Depense;
use App\Entity\TokenPurchase;
use App\Repository\DepenseRepository;
use App\Repository\PlateformeParametresRepository;
use App\Repository\TokenPurchaseRepository;
use App\Services\ServiceTaxesVente;
use App\Token\ParametresTokenService;

/**
 * @file Génération « à la volée » des écritures comptables de JS Brokers.
 * @description JS Brokers ne tient pas de comptabilité en partie double stockée :
 * ce service la DÉRIVE des données transactionnelles (ventes de tokens, dépenses,
 * capital social) via un plan comptable SYSCOHADA déterministe (cf. PlanComptable).
 * Source unique de tous les documents financiers (journal, grand livre, balance,
 * compte de résultat, TFR, bilan, TFT). Lecture seule, aucune persistance.
 *
 * Cohérence garantie avec les KPI du tableau de bord : produit = revenu HT
 * (ServiceTaxesVente), charges = HT des dépenses non annulées, trésorerie =
 * encaissements − décaissements payés. Chaque écriture est équilibrée
 * (Σ débit = Σ crédit) → le bilan s'équilibre toujours.
 */
class EcritureComptableService
{
    /** Cache des écritures générées (toutes périodes), construit une seule fois. */
    private ?array $ecritures = null;

    public function __construct(
        private TokenPurchaseRepository $purchaseRepository,
        private DepenseRepository $depenseRepository,
        private PlateformeParametresRepository $parametresRepository,
        private ServiceTaxesVente $taxesVente,
        private ParametresTokenService $parametresToken,
    ) {
    }

    /**
     * Toutes les écritures, tous exercices confondus, triées par date croissante.
     * Chaque écriture : { date, piece, libelle, type, lignes:[{compte, libelle,
     * debit, credit}] }.
     *
     * @return array<int, array{date:\DateTimeImmutable, piece:string, libelle:string, type:string, lignes:array<int, array{compte:string, libelle:string, debit:float, credit:float}>}>
     */
    public function ecritures(): array
    {
        if ($this->ecritures !== null) {
            return $this->ecritures;
        }

        $ecritures = [];

        // 1) Capital social (apport d'ouverture) — écriture fondatrice éventuelle.
        $ecritureCapital = $this->ecritureCapital();
        if ($ecritureCapital !== null) {
            $ecritures[] = $ecritureCapital;
        }

        // 2) Ventes (revenus) : encaissement TTC, produit HT, TVA collectée.
        foreach ($this->purchaseRepository->findChronologique() as $vente) {
            $ecritures[] = $this->ecritureVente($vente);
        }

        // 3) Dépenses (charges) non annulées : charge HT, TVA déductible, sortie TTC.
        foreach ($this->depenseRepository->findChronologique() as $depense) {
            $ecritures[] = $this->ecritureDepense($depense);
        }

        // Tri chronologique stable (les écritures sans pièce gardent leur ordre relatif).
        usort($ecritures, static fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $this->ecritures = $ecritures;
    }

    /**
     * Les six documents comptables d'un exercice (année civile), prêts à l'affichage
     * et à l'export. Voir les méthodes privées pour la structure de chaque document.
     *
     * @return array{exercice:int, ouverture:\DateTimeImmutable, cloture:\DateTimeImmutable, journal:array, grandLivre:array, balance:array, resultat:array, tfr:array, bilan:array, tft:array}
     */
    public function documents(int $exercice): array
    {
        $debut = new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $exercice));
        $fin   = new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $exercice + 1));

        $toutes = $this->ecritures();
        $avant  = array_filter($toutes, static fn (array $e) => $e['date'] < $debut);
        $periode = array_filter($toutes, static fn (array $e) => $e['date'] >= $debut && $e['date'] < $fin);

        $aggOuverture = $this->aggreger($avant);
        $aggPeriode   = $this->aggreger($periode);
        $aggCloture   = $this->fusionner($aggOuverture, $aggPeriode);

        return [
            'exercice'   => $exercice,
            'ouverture'  => $debut,
            'cloture'    => $fin->modify('-1 day'),
            'journal'    => $this->journal($periode),
            'grandLivre' => $this->grandLivre($periode, $aggOuverture),
            'balance'    => $this->balance($aggOuverture, $aggPeriode, $aggCloture),
            'resultat'   => $this->resultat($aggPeriode),
            'tfr'        => $this->tfr($aggPeriode),
            'bilan'      => $this->bilan($aggOuverture, $aggCloture, $aggPeriode),
            'tft'        => $this->tft($periode, $aggOuverture, $aggCloture),
        ];
    }

    /**
     * Années civiles présentes dans les données (pour le sélecteur d'exercice),
     * de la plus récente à la plus ancienne. À défaut : l'année courante.
     *
     * @return int[]
     */
    public function exercicesDisponibles(): array
    {
        $annees = [];
        foreach ($this->ecritures() as $e) {
            $annees[(int) $e['date']->format('Y')] = true;
        }

        if ($annees === []) {
            return [(int) date('Y')];
        }

        $liste = array_keys($annees);
        rsort($liste);

        return $liste;
    }

    // ===================== Génération des écritures =====================

    /** Écriture de vente : D 521 Banques (TTC) / C 706 Services vendus (HT) / C 443 TVA (collectée). */
    private function ecritureVente(TokenPurchase $vente): array
    {
        $ttc = round((float) $vente->getMontantUsd(), 2);
        $ht  = round($this->taxesVente->revenuHorsTaxe($ttc), 2);
        $tva = round($ttc - $ht, 2);

        $lignes = [
            $this->ligne(PlanComptable::BANQUES, $ttc, 0.0),
            $this->ligne(PlanComptable::SERVICES_VENDUS, 0.0, $ht),
        ];
        if (abs($tva) >= 0.005) {
            $lignes[] = $this->ligne(PlanComptable::TVA_FACTUREE, 0.0, $tva);
        }

        return [
            'date'    => $vente->getCreatedAt(),
            'piece'   => $vente->getReference() ?? ('V-' . $vente->getId()),
            'libelle' => sprintf('Vente paquet %s', $this->libellePaquet((string) $vente->getPack())),
            'type'    => 'vente',
            'lignes'  => $lignes,
        ];
    }

    /**
     * Libellé public d'un paquet à partir de sa clé technique : MÊME source que la
     * vitrine / section Tarif (ParametresTokenService → label configuré, repli
     * TokenPricing::PACKS), avec repli sur la clé capitalisée si absente. Identique
     * au filtre Twig `token_pack_label` (DRY).
     */
    private function libellePaquet(string $key): string
    {
        return $this->parametresToken->pack($key)['label'] ?? ucfirst(mb_strtolower($key));
    }

    /**
     * Écriture de dépense : D compte de charge (HT) [+ D 445 TVA récupérable] /
     * C trésorerie (si payée) ou C 401 Fournisseurs (si engagée), pour le TTC.
     */
    private function ecritureDepense(Depense $depense): array
    {
        $ttc = round($depense->getMontantFloat(), 2);
        $ht  = round($depense->getMontantHtFloat(), 2);
        $tva = round($ttc - $ht, 2);

        $compteCharge = $depense->getCharge()?->getCompteOhada() ?? '60';

        $lignes = [$this->ligne($compteCharge, $ht, 0.0)];
        if (abs($tva) >= 0.005) {
            $lignes[] = $this->ligne(PlanComptable::TVA_RECUPERABLE, $tva, 0.0);
        }

        // Contrepartie au crédit : trésorerie si payée, dette fournisseur si engagée.
        $compteContrepartie = $depense->getStatut() === Depense::STATUT_PAYEE
            ? ($depense->getMoyenPaiement() === Depense::MOYEN_CAISSE ? PlanComptable::CAISSE : PlanComptable::BANQUES)
            : PlanComptable::FOURNISSEURS;
        $lignes[] = $this->ligne($compteContrepartie, 0.0, $ttc);

        $libelle = $depense->getCharge()?->getLibelle() ?? 'Dépense';
        if ($depense->getBeneficiaire() !== null && $depense->getBeneficiaire() !== '') {
            $libelle .= ' — ' . $depense->getBeneficiaire();
        }

        return [
            'date'    => $depense->getDateDepense(),
            'piece'   => $depense->getReference() ?? ('D-' . $depense->getId()),
            'libelle' => $libelle,
            'type'    => 'depense',
            'lignes'  => $lignes,
        ];
    }

    /** Écriture du capital social (D 521 Banques / C 101 Capital social), ou null si non renseigné. */
    private function ecritureCapital(): ?array
    {
        $params = $this->parametresRepository->getSingleton();
        $capital = round($params->getCapitalSocialFloat(), 2);
        if ($capital < 0.005) {
            return null;
        }

        // Date de l'apport : date de constitution si renseignée, sinon la 1ʳᵉ opération,
        // sinon le 1ᵉʳ janvier de l'année courante (cas d'une plateforme sans flux).
        $date = $params->getDateConstitution() ?? $this->premiereOperation() ?? new \DateTimeImmutable('first day of January this year 00:00:00');

        return [
            'date'    => $date,
            'piece'   => 'CAPITAL',
            'libelle' => 'Apport en capital social',
            'type'    => 'capital',
            'lignes'  => [
                $this->ligne(PlanComptable::BANQUES, $capital, 0.0),
                $this->ligne(PlanComptable::CAPITAL_SOCIAL, 0.0, $capital),
            ],
        ];
    }

    /** Date de la première opération (vente ou dépense), pour dater le capital à défaut. */
    private function premiereOperation(): ?\DateTimeImmutable
    {
        $dates = [];
        foreach ($this->purchaseRepository->findChronologique() as $v) {
            if ($v->getCreatedAt() !== null) {
                $dates[] = $v->getCreatedAt();
            }
        }
        foreach ($this->depenseRepository->findChronologique() as $d) {
            if ($d->getDateDepense() !== null) {
                $dates[] = $d->getDateDepense();
            }
        }

        if ($dates === []) {
            return null;
        }

        return min($dates);
    }

    /** Construit une ligne d'écriture normalisée (compte + libellé résolu). */
    private function ligne(string $compte, float $debit, float $credit): array
    {
        return [
            'compte'  => $compte,
            'libelle' => PlanComptable::libelle($compte),
            'debit'   => $debit,
            'credit'  => $credit,
        ];
    }

    // ===================== Agrégation par compte =====================

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
    private function grandLivre(array $periode, array $aggOuverture): array
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
                'libelle'     => PlanComptable::libelle($compte),
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
    private function balance(array $aggOuverture, array $aggPeriode, array $aggCloture): array
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
                'libelle' => PlanComptable::libelle($compte),
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
    private function resultat(array $aggPeriode): array
    {
        $produits = [];
        $charges = [];
        $totalProduits = 0.0;
        $totalCharges = 0.0;

        foreach ($aggPeriode as $compte => $v) {
            $classe = PlanComptable::classe($compte);
            if ($classe === 7) {
                $montant = round($v['credit'] - $v['debit'], 2);
                $produits[] = ['compte' => $compte, 'libelle' => PlanComptable::libelle($compte), 'montant' => $montant];
                $totalProduits += $montant;
            } elseif ($classe === 6) {
                $montant = round($v['debit'] - $v['credit'], 2);
                $charges[] = ['compte' => $compte, 'libelle' => PlanComptable::libelle($compte), 'montant' => $montant];
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
     * est identique à celui du compte de résultat (même base de comptes).
     *
     * @return array<int, array{libelle:string, montant:float, solde:bool}>
     */
    private function tfr(array $aggPeriode): array
    {
        // Charge HT d'un compte OHADA classe 6 (solde débiteur de la période).
        $charge = fn (string $compte): float => max($this->solde($aggPeriode, $compte), 0.0);

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
            ['libelle' => 'Chiffre d\'affaires (produit HT)', 'montant' => $ca, 'solde' => true],
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
                    'total'        => round($capital + $report + $resultat + $fournisseurs + $tvaFacturee, 2),
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
                ['libelle' => 'TOTAL PASSIF', 'ouverture' => $ouv['passif']['total'], 'cloture' => $clo['passif']['total'], 'total' => true],
            ],
        ];
    }

    /**
     * Tableau de flux de trésorerie : trésorerie d'ouverture + flux d'exploitation
     * (encaissements clients − décaissements) + flux de financement (apports en
     * capital) = trésorerie de clôture. Reconcilie avec le bilan.
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
                } elseif ($e['type'] === 'vente') {
                    $encaissements += $l['debit'] - $l['credit'];
                } else { // depense
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
