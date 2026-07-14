<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Services\DashboardDataProvider;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * Outil de données : ANALYSES AGRÉGÉES du portefeuille — classements (tops
 * assureurs / clients / risques / intermédiaires par primes, commissions,
 * sinistralité, part de marché), production encaissée par mois, derniers
 * encaissements. Complète indicateur_calcule (valeur unitaire) et
 * statistiques (agrégats de champs stockés) par les agrégats MÉTIER du
 * tableau de bord.
 *
 * COÛT : les tops hydratent les valeurs calculées de TOUS les avenants actifs
 * (DashboardDataProvider::getAvenantsActifsHydrates, mémoïsé par requête HTTP
 * — les rounds de tool-calling successifs réutilisent le cache). Une analyse
 * par appel.
 *
 * FAIL-CLOSED : les tops dérivent des Avenants — sans droit de lecture sur
 * Avenant ET l'entité classée, refus. La sinistralité (ratio S/P, montants
 * indemnisés) est OMISE si l'invité ne lit pas les sinistres.
 */
final class AnalysePortefeuilleTool implements AiToolInterface
{
    /** analyse => [méthode du provider (tops), entités dont la lecture est exigée]. */
    private const ANALYSES = [
        'top_assureurs'        => ['getTopAssureursAvecIndicateurs',      ['Avenant', 'Assureur']],
        'top_clients'          => ['getTopAssuresAvecIndicateurs',        ['Avenant', 'Client']],
        'top_risques'          => ['getTopRisquesAvecIndicateurs',        ['Avenant', 'Risque']],
        'top_intermediaires'   => ['getTopIntermediairesAvecIndicateurs', ['Avenant', 'Partenaire']],
        'production_mensuelle' => [null, ['Paiement']],
        'encaissements'        => [null, ['Paiement']],
    ];

    private const LIMITE_DEFAUT = 5;
    /** Aligné sur DashboardDataProvider::sliceAvecRestes(top: 9). */
    private const LIMITE_MAX = 9;
    private const MAX_ENCAISSEMENTS = 10;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly DashboardDataProvider $dashboard,
    ) {
    }

    public function name(): string
    {
        return 'analyse_portefeuille';
    }

    public function description(): string
    {
        return 'Analyses agrégées du portefeuille du cabinet : classements « top » des assureurs, '
            . 'clients, risques ou intermédiaires (nombre de polices, primes, commissions, '
            . 'sinistralité, part de marché — polices actives), production encaissée par mois, '
            . 'derniers encaissements. À appeler pour « quel est notre meilleur assureur ? », '
            . '« top 5 clients », « production mensuelle 2026 ». Pour un indicateur unitaire, '
            . 'préférer indicateur_calcule ; pour compter/lister, compter_entites / rechercher_entites.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'analyse' => [
                    'type' => 'string',
                    'enum' => array_keys(self::ANALYSES),
                    'description' => 'Analyse demandée : classement du portefeuille, production '
                        . 'encaissée par mois, ou derniers encaissements.',
                ],
                'limite' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::LIMITE_MAX,
                    'description' => 'Taille du top (défaut ' . self::LIMITE_DEFAUT . ', max ' . self::LIMITE_MAX . ').',
                ],
                'annee' => [
                    'type' => 'integer',
                    'description' => 'Année de la production mensuelle (défaut : année courante).',
                ],
            ],
            'required' => ['analyse'],
        ];
    }

    /** Chemin simulé : « top (5) assureurs/clients/risques… », « production mensuelle (2026) », « derniers encaissements ». */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        $tops = [
            'top_assureurs'      => '/\btop\b.*\bassureurs?\b|\bmeilleurs?\s+assureurs?\b',
            'top_clients'        => '/\btop\b.*\b(clients?|assures?)\b|\bmeilleurs?\s+clients?\b',
            'top_risques'        => '/\btop\b.*\brisques?\b|\bmeilleurs?\s+risques?\b',
            'top_intermediaires' => '/\btop\b.*\b(intermediaires?|partenaires?)\b|\bmeilleurs?\s+(intermediaires?|partenaires?)\b',
        ];
        foreach ($tops as $analyse => $pattern) {
            if (preg_match($pattern . '/', $normalized)) {
                $args = ['analyse' => $analyse];
                if (preg_match('/\btop\s*(\d{1,2})\b/', $normalized, $m)) {
                    $args['limite'] = (int) $m[1];
                }

                return $args;
            }
        }

        if (preg_match('/\bproduction\s+(mensuelle|par mois)\b/', $normalized)) {
            $args = ['analyse' => 'production_mensuelle'];
            if (preg_match('/\b(20\d{2})\b/', $normalized, $m)) {
                $args['annee'] = (int) $m[1];
            }

            return $args;
        }

        // « liste/affiche les encaissements » reste le domaine de rechercher_entites.
        if (preg_match('/\b(derniers\s+)?encaissements\b/', $normalized)
            && !preg_match('/\b(liste[rz]?|affiche[rz]?)\b/', $normalized)) {
            return ['analyse' => 'encaissements'];
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $analyse = (string) ($args['analyse'] ?? '');
        if (!isset(self::ANALYSES[$analyse])) {
            return AiToolResult::introuvable($analyse);
        }

        // FAIL-CLOSED : lecture exigée sur TOUTES les entités dont dérive l'analyse.
        [$methode, $gates] = self::ANALYSES[$analyse];
        $labels = $this->accessResolver->libellesEntites();
        foreach ($gates as $gate) {
            if (!$this->accessResolver->canRead($scope->invite, $gate)) {
                return AiToolResult::horsPerimetre($labels[$gate] ?? $gate);
            }
        }

        return match ($analyse) {
            'production_mensuelle' => $this->productionMensuelle($scope, $args),
            'encaissements'        => $this->encaissements($scope),
            default                => $this->top($analyse, $methode, $scope, $args),
        };
    }

    private function top(string $analyse, string $methode, AiScope $scope, array $args): AiToolResult
    {
        $limite = max(1, min(self::LIMITE_MAX, (int) ($args['limite'] ?? self::LIMITE_DEFAUT)));
        $rows = $this->dashboard->{$methode}($scope->entreprise);

        // La sinistralité dérive des sinistres : omise hors périmètre sinistres.
        $avecSinistralite = $this->accessResolver->canRead($scope->invite, 'NotificationSinistre');

        $lignes = array_map(
            static function (array $row) use ($avecSinistralite): array {
                $ligne = [
                    'nom'            => $row['nom'],
                    'nbPolices'      => $row['nbPolices'],
                    'primesTotales'  => round((float) $row['primesTotales'], 2),
                    'commissionsTtc' => round((float) $row['commissionsTtc'], 2),
                    'partMarche'     => $row['partMarche'] ?? 0.0,
                ];
                if ($avecSinistralite) {
                    $ligne['sinistresIndemnises'] = round((float) ($row['sinistresIndemnises'] ?? 0), 2);
                    $ligne['ratioSP'] = $row['ratioSP'] ?? 0.0;
                }

                return $ligne;
            },
            array_slice($rows, 0, $limite),
        );

        return AiToolResult::ok(array_filter([
            'analyse' => $analyse,
            'lignes'  => $lignes,
            'note'    => $avecSinistralite ? null
                : 'Sinistralité omise : les sinistres sont hors du périmètre de l\'utilisateur.',
        ], static fn ($v) => $v !== null));
    }

    private function productionMensuelle(AiScope $scope, array $args): AiToolResult
    {
        $annee = (int) ($args['annee'] ?? 0);
        if ($annee > 0) {
            $prod = $this->dashboard->getProductionParMois(
                $scope->entreprise,
                new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $annee)),
                new \DateTimeImmutable(sprintf('%d-12-31 23:59:59', $annee)),
            );
            // Réindexe 1..12 (même forme que getProductionMensuelle).
            $mensuel = array_combine(range(1, 12), $prod['data']);
        } else {
            $annee = (int) date('Y');
            $mensuel = $this->dashboard->getProductionMensuelle($scope->entreprise);
        }

        return AiToolResult::ok([
            'analyse' => 'production_mensuelle',
            'annee'   => $annee,
            'mois'    => array_map(static fn (float $m) => round($m, 2), $mensuel),
            'total'   => round(array_sum($mensuel), 2),
        ]);
    }

    private function encaissements(AiScope $scope): AiToolResult
    {
        $paiements = $this->dashboard->getDerniersEncaissements($scope->entreprise, self::MAX_ENCAISSEMENTS);

        $lignes = array_map(
            static fn (object $p) => array_filter([
                'id'        => $p->getId(),
                'montant'   => round((float) $p->getMontant(), 2),
                'reference' => $p->getReference(),
                'paidAt'    => $p->getPaidAt()?->format('Y-m-d'),
            ], static fn ($v) => $v !== null && $v !== ''),
            $paiements,
        );

        return AiToolResult::ok([
            'analyse' => 'encaissements',
            'lignes'  => $lignes,
        ]);
    }
}
