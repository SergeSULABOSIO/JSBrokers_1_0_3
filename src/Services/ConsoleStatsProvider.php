<?php

namespace App\Services;

use App\Entity\Charge;
use App\Repository\DepenseRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\TokenPurchaseRepository;
use App\Repository\UtilisateurRepository;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * @file Agrégats globaux de la plateforme pour le tableau de bord de la Console.
 * @description Centralise les KPIs (comptes, ventes, revenu, tokens) et la
 * construction des graphiques Chart.js (même bundle/pattern que JSBChartBuilder).
 * Lecture seule : ne modifie aucune donnée.
 */
class ConsoleStatsProvider
{
    /** Palette cohérente avec JSBChartBuilder, accent cobalt en tête. */
    private const COLORS = [
        'rgb(0,71,171)',    // cobalt (marque)
        'rgb(77,77,255)',
        'rgb(255,192,77)',
        'rgb(231,146,86)',
        'rgb(205,0,205)',
        'rgb(77,255,77)',
        'rgb(255,77,77)',
        'rgb(166,166,166)',
    ];

    public function __construct(
        private UtilisateurRepository $utilisateurRepository,
        private EntrepriseRepository $entrepriseRepository,
        private TokenPurchaseRepository $purchaseRepository,
        private ChartBuilderInterface $chartBuilder,
        private ServiceGeographie $geographie,
        private ServiceTaxesVente $taxesVente,
        private DepenseRepository $depenseRepository,
    ) {
    }

    /**
     * KPIs chiffrés du tableau de bord.
     *
     * Les volumes d'affaires (ventes, tokens, revenu, remises, revenu HT, taxes)
     * portent sur l'ANNÉE CIVILE EN COURS. Les effectifs (collaborateurs,
     * utilisateurs, clients, entreprises) et le taux de conversion restent des
     * indicateurs cumulés (états actuels, non datés).
     *
     * @return array<string, mixed>
     */
    public function getKpis(): array
    {
        $annee  = (int) date('Y');
        $bornes = ['from' => sprintf('%d-01-01', $annee), 'to' => sprintf('%d-12-31', $annee)];
        $totals = $this->purchaseRepository->totals($bornes);

        // Conversion Utilisateur → Client (cumulée) : part des comptes en mode
        // payant (clients) parmi tous les comptes « classiques » (gratuits +
        // payants). Cohérente avec les compteurs Utilisateurs / Clients affichés.
        $nbUsers   = $this->utilisateurRepository->countRegularUsers();
        $nbClients = $this->utilisateurRepository->countClients();
        $base      = $nbUsers + $nbClients;

        return [
            'annee'           => $annee,
            // Effectifs cumulés (états actuels).
            'nbAgents'        => count($this->utilisateurRepository->findAgents()),
            'nbUsers'         => $nbUsers,
            'nbClients'       => $nbClients,
            'nbEntreprises'   => $this->entrepriseRepository->countAllGlobal(),
            // Volumes d'affaires de l'année en cours.
            'nbVentes'        => $totals['count'],
            'tokensVendus'    => $totals['tokens'],
            'revenuUsd'       => $totals['revenue'],
            'remisesUsd'      => $totals['remises'],
            // Conversion cumulée (cohérente avec les compteurs ci-dessus).
            'tauxConversion'  => $base > 0 ? round($nbClients / $base * 100, 1) : 0.0,
            'revenuHorsTaxe'  => $this->taxesVente->revenuHorsTaxe($totals['revenue']),
            // Une entrée par taxe active : montant représenté sur le revenu de l'année
            // (alimente une carte KPI dédiée par taxe).
            'taxes'           => $this->taxesVente->ventilation($totals['revenue']),
            // Indicateurs financiers (compte de résultat simplifié + trésorerie) et
            // SaaS (ARPC, CAC, marge brute, rétention) dégagés grâce aux dépenses.
            'finance'         => $this->financeKpis($annee, $bornes, $totals['revenue']),
            'saas'            => $this->saasKpis($annee, $bornes, $totals['revenue'], $nbClients),
        ];
    }

    /**
     * KPIs financiers de l'année en cours : produit (HT), charges (dépenses non
     * annulées), résultat, et trésorerie en position cumulée depuis l'origine
     * (encaissements des ventes − décaissements des dépenses payées).
     *
     * @param array{from:string, to:string} $bornes
     *
     * @return array{produit:float, charges:float, resultat:float, tresorerie:float}
     */
    private function financeKpis(int $annee, array $bornes, float $revenuTtc): array
    {
        $produit = $this->taxesVente->revenuHorsTaxe($revenuTtc);
        $charges = $this->depenseRepository->totalCharges($bornes['from'], $bornes['to']);

        // Trésorerie = cumul de tous les encaissements (ventes) − cumul de tous les
        // décaissements (dépenses payées), sans borne de date (solde de caisse réel).
        $encaissementsCumules = $this->purchaseRepository->totals([])['revenue'];
        $decaissementsCumules = $this->depenseRepository->totalPaye();

        return [
            'produit'    => $produit,
            'charges'    => $charges,
            'resultat'   => $produit - $charges,
            'tresorerie' => $encaissementsCumules - $decaissementsCumules,
        ];
    }

    /**
     * KPIs SaaS de l'année en cours : revenu moyen par client (ARPC), coût
     * d'acquisition client (CAC), marge brute (%) et taux de rétention (%) déduit
     * du ré-achat mensuel (mois en cours vs mois précédent).
     *
     * @param array{from:string, to:string} $bornes
     *
     * @return array{arpc:float, cac:float, margeBrute:float, retention:float}
     */
    private function saasKpis(int $annee, array $bornes, float $revenuTtc, int $nbClients): array
    {
        $produit      = $this->taxesVente->revenuHorsTaxe($revenuTtc);
        $coutsDirects = $this->depenseRepository->totalByDestination(Charge::DEST_COUT_DIRECT, $bornes['from'], $bornes['to']);
        $acquisition  = $this->depenseRepository->totalByDestination(Charge::DEST_ACQUISITION, $bornes['from'], $bornes['to']);
        $nbNouveaux   = $this->purchaseRepository->countNewClients($bornes['from'], $bornes['to']);

        return [
            'arpc'       => $nbClients > 0 ? $revenuTtc / $nbClients : 0.0,
            'cac'        => $nbNouveaux > 0 ? $acquisition / $nbNouveaux : 0.0,
            'margeBrute' => $produit > 0 ? ($produit - $coutsDirects) / $produit * 100 : 0.0,
            'retention'  => $this->retentionMensuelle(),
        ];
    }

    /**
     * Taux de rétention par ré-achat : part des clients actifs le mois précédent
     * qui ont de nouveau acheté le mois en cours. Seul signal de fidélité fiable
     * (la plateforme ne trace pas l'activité de connexion). 0 si aucun client le
     * mois précédent.
     */
    private function retentionMensuelle(): float
    {
        $debutMois     = new \DateTimeImmutable('first day of this month 00:00:00');
        $debutMoisPrec = $debutMois->modify('-1 month');
        $finMois       = $debutMois->modify('+1 month');

        $moisPrecedent = $this->purchaseRepository->buyerIdsForPeriod($debutMoisPrec, $debutMois);
        if ($moisPrecedent === []) {
            return 0.0;
        }

        $moisCourant = $this->purchaseRepository->buyerIdsForPeriod($debutMois, $finMois);
        $fideles     = array_intersect($moisPrecedent, $moisCourant);

        return count($fideles) / count($moisPrecedent) * 100;
    }

    /**
     * Histogramme du revenu des ventes par mois (janvier → décembre) de l'année
     * civile en cours.
     *
     * @return array{chart: Chart, titre: string}
     */
    public function chartVentesParMois(): array
    {
        $annee = (int) date('Y');
        $serie = $this->purchaseRepository->seriesParMoisAnnee($annee);

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $serie['labels'],
            'datasets' => [[
                'label'           => 'Revenu (USD)',
                'backgroundColor' => self::COLORS[0],
                'borderColor'     => 'white',
                'data'            => $serie['revenue'],
            ]],
        ]);
        $chart->setOptions([
            'responsive'          => true,
            'maintainAspectRatio' => false,
            'scales'              => ['y' => ['beginAtZero' => true]],
        ]);

        return ['chart' => $chart, 'titre' => sprintf('Revenu des ventes par mois (%d)', $annee)];
    }

    /**
     * Histogramme du revenu des ventes par pays pour l'année civile en cours.
     * Le pays d'une vente est dérivé de l'entreprise représentative de
     * l'acheteur (sa première entreprise détenue, à défaut l'entreprise active) ;
     * les ventes sans pays identifiable sont regroupées sous « Inconnu ».
     *
     * @return array{chart: Chart, titre: string}
     */
    public function chartVentesParPays(): array
    {
        $annee = (int) date('Y');

        $parPays = [];
        foreach ($this->purchaseRepository->findAnnee($annee) as $vente) {
            $acheteur   = $vente->getUtilisateur();
            $entreprise = null;
            if ($acheteur !== null) {
                $entreprise = $acheteur->getEntreprises()->first() ?: $acheteur->getConnectedTo();
            }

            $nom = 'Inconnu';
            if ($entreprise !== null && $entreprise->getPays() !== null) {
                $nom = $this->geographie->getNomPays($entreprise->getPays()) ?? 'Inconnu';
            }

            $parPays[$nom] = ($parPays[$nom] ?? 0.0) + (float) $vente->getMontantUsd();
        }

        arsort($parPays); // pays les plus contributeurs en tête

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => array_keys($parPays),
            'datasets' => [[
                'label'           => 'Revenu (USD)',
                'backgroundColor' => self::COLORS[0],
                'borderColor'     => 'white',
                'data'            => array_values($parPays),
            ]],
        ]);
        $chart->setOptions([
            'responsive'          => true,
            'maintainAspectRatio' => false,
            'scales'              => ['y' => ['beginAtZero' => true]],
        ]);

        return ['chart' => $chart, 'titre' => sprintf('Revenu des ventes par pays (%d)', $annee)];
    }

    /**
     * Camembert du revenu par paquet de tokens, pour l'année civile en cours.
     *
     * @return array{chart: Chart, titre: string}
     */
    public function chartVentesParPaquet(): array
    {
        $annee = (int) date('Y');
        $rows = $this->purchaseRepository->groupByPack([
            'from' => sprintf('%d-01-01', $annee),
            'to'   => sprintf('%d-12-31', $annee),
        ]);

        $chart = $this->chartBuilder->createChart(Chart::TYPE_PIE);
        $chart->setData([
            'labels' => array_map(static fn ($r) => ucfirst($r['pack']), $rows),
            'datasets' => [[
                'label'           => 'Revenu (USD)',
                'backgroundColor' => self::COLORS,
                'borderColor'     => 'white',
                'data'            => array_map(static fn ($r) => $r['revenue'], $rows),
                'hoverOffset'     => 30,
            ]],
        ]);
        $chart->setOptions([
            'responsive'          => true,
            'maintainAspectRatio' => false,
        ]);

        return ['chart' => $chart, 'titre' => sprintf('Répartition du revenu par paquet (%d)', $annee)];
    }
}
