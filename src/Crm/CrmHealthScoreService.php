<?php

namespace App\Crm;

/**
 * @file Score de santé client (Customer Success).
 * @description Somme pondérée de 6 critères, chacun normalisé sur 0‑100, à partir
 * des seuls signaux déjà connus de la plateforme (aucune ressaisie). Renvoie le
 * score global, sa couleur (charte) et le détail par critère pour l'affichage.
 *
 * Poids (total 100) : Engagement 25, Consommation 20, Autonomie 15, Adoption 15,
 * Monétisation 15, Support 10. Les poids sont des constantes pour l'instant ;
 * la phase « Paramètres CRM » les rendra éditables en BDD.
 */
class CrmHealthScoreService
{
    public const WEIGHTS = [
        'engagement'   => 25,
        'consommation' => 20,
        'autonomie'    => 15,
        'adoption'     => 15,
        'monetisation' => 15,
        'support'      => 10,
    ];

    private const LABELS = [
        'engagement'   => 'Engagement / récence',
        'consommation' => 'Consommation (30 j)',
        'autonomie'    => 'Autonomie (solde)',
        'adoption'     => 'Adoption',
        'monetisation' => 'Monétisation',
        'support'      => 'Support / satisfaction',
    ];

    /** Cible de consommation mensuelle (tokens) considérée comme « pleinement actif ». */
    private const CONSO_CIBLE_30J = 500;

    /**
     * Calcule le score à partir des signaux agrégés.
     *
     * @param array{
     *   lastActivityAt:?\DateTimeImmutable, consumption30:int, paidTokens:int,
     *   nbEntreprises:int, nbInvites:int, distinctEntites:int,
     *   nbPurchases:int, lastPurchaseAt:?\DateTimeImmutable, openTickets:int
     * } $s
     *
     * @return array{score:int, couleur:string, details:array<int, array{key:string, label:string, weight:int, pct:int}>}
     */
    public function compute(array $s): array
    {
        $now = new \DateTimeImmutable();

        $daysSinceActivity = $s['lastActivityAt'] instanceof \DateTimeInterface
            ? (int) $s['lastActivityAt']->diff($now)->days
            : null;

        // 1. Engagement : récence d'activité (0 j = 100 %, ≥ 30 j = 0 %).
        $engagement = $daysSinceActivity === null ? 0 : (int) max(0, 100 - ($daysSinceActivity / 30 * 100));

        // 2. Consommation : volume des 30 derniers jours vs cible.
        $consommation = (int) min(100, $s['consumption30'] / self::CONSO_CIBLE_30J * 100);

        // 3. Autonomie : nombre de jours de tokens restants au rythme courant.
        $dailyAvg = $s['consumption30'] / 30;
        if ($s['paidTokens'] <= 0) {
            $autonomie = $s['consumption30'] > 0 ? 15 : 30; // compte gratuit : marge limitée
        } elseif ($dailyAvg <= 0) {
            $autonomie = 100; // solde présent, pas de consommation récente
        } else {
            $runwayDays = $s['paidTokens'] / $dailyAvg;
            $autonomie = (int) min(100, $runwayDays / 30 * 100);
        }

        // 4. Adoption : largeur d'usage (entreprises, invités, diversité d'entités).
        $adoption = (int) min(100, $s['nbEntreprises'] * 20 + $s['nbInvites'] * 10 + $s['distinctEntites'] * 8);

        // 5. Monétisation : récence + fréquence des achats.
        if ($s['nbPurchases'] === 0) {
            $monetisation = 0;
        } else {
            $daysSincePurchase = $s['lastPurchaseAt'] instanceof \DateTimeInterface
                ? (int) $s['lastPurchaseAt']->diff($now)->days
                : 999;
            $recence = (int) max(0, 100 - ($daysSincePurchase / 90 * 100));
            $frequence = (int) min(100, $s['nbPurchases'] * 20);
            $monetisation = (int) round($recence * 0.6 + $frequence * 0.4);
        }

        // 6. Support : 100 % par défaut, pénalité par ticket ouvert / en retard.
        $support = (int) max(0, 100 - ($s['openTickets'] * 25));

        $pcts = [
            'engagement'   => $engagement,
            'consommation' => $consommation,
            'autonomie'    => $autonomie,
            'adoption'     => $adoption,
            'monetisation' => $monetisation,
            'support'      => $support,
        ];

        $score = 0.0;
        $details = [];
        foreach (self::WEIGHTS as $key => $weight) {
            $pct = $pcts[$key];
            $score += $weight * $pct / 100;
            $details[] = [
                'key'    => $key,
                'label'  => self::LABELS[$key],
                'weight' => $weight,
                'pct'    => $pct,
            ];
        }

        $score = (int) round($score);

        return [
            'score'   => $score,
            'couleur' => $this->color($score),
            'details' => $details,
        ];
    }

    /** Couleur (charte) selon le score : vert / jaune / orange / rouge. */
    public function color(int $score): string
    {
        return match (true) {
            $score >= 75 => 'vert',
            $score >= 50 => 'jaune',
            $score >= 25 => 'orange',
            default      => 'rouge',
        };
    }

    /** Libellé humain de la couleur. */
    public function colorLabel(string $couleur): string
    {
        return match ($couleur) {
            'vert'   => 'En bonne santé',
            'jaune'  => 'À surveiller',
            'orange' => 'À risque',
            default  => 'Critique',
        };
    }

    /** Code hexadécimal de la couleur (charte JS Brokers). */
    public function colorHex(string $couleur): string
    {
        return match ($couleur) {
            'vert'   => '#137333',
            'jaune'  => '#b58100',
            'orange' => '#c05600',
            default  => '#c5221f',
        };
    }
}
