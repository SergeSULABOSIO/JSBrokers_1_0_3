<?php

namespace App\Crm;

use App\Repository\PlateformeParametresRepository;

/**
 * @file Fournisseur runtime des paramètres CRM (poids de santé, seuils, automatisations).
 * @description Même pattern que App\Token\ParametresTokenService : lecture
 * dynamique depuis PlateformeParametres (éditable en Console par le super-admin),
 * avec repli champ par champ sur les valeurs par défaut. Tant qu'aucun paramètre
 * n'est personnalisé, le comportement est identique aux constantes (zéro régression).
 */
class ParametresCrmService
{
    /** Seuils de couleur par défaut du score de santé. */
    public const DEFAULT_THRESHOLDS = ['vert' => 75, 'jaune' => 50, 'orange' => 25];

    /** Paramètres d'automatisation par défaut. */
    public const DEFAULT_AUTOMATION = [
        'inactiviteJours' => 15,   // relance après N jours sans connexion
        'soldeBas'        => 1000, // seuil « presque à court de tokens »
        'churnJours'      => 45,   // bascule churn d'un compte engagé inactif
    ];

    private ?array $cache = null;

    public function __construct(private PlateformeParametresRepository $repository)
    {
    }

    public function refresh(): void
    {
        $this->cache = null;
    }

    private function values(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $p = $this->repository->getSingleton();

        return $this->cache = [
            'weights'    => $this->mergeInts($p->getCrmHealthWeights(), CrmHealthScoreService::WEIGHTS),
            'thresholds' => $this->mergeInts($p->getCrmThresholds(), self::DEFAULT_THRESHOLDS),
            'automation' => $this->mergeInts($p->getCrmAutomation(), self::DEFAULT_AUTOMATION),
        ];
    }

    /** @return array<string,int> */
    public function healthWeights(): array
    {
        return $this->values()['weights'];
    }

    /** @return array{vert:int, jaune:int, orange:int} */
    public function thresholds(): array
    {
        return $this->values()['thresholds'];
    }

    public function inactiviteJours(): int
    {
        return $this->values()['automation']['inactiviteJours'];
    }

    public function soldeBas(): int
    {
        return $this->values()['automation']['soldeBas'];
    }

    public function churnJours(): int
    {
        return $this->values()['automation']['churnJours'];
    }

    /**
     * Fusionne des valeurs entières personnalisées (BDD) sur des valeurs par défaut,
     * en ignorant les clés absentes ou non numériques (repli sûr, clé par clé).
     *
     * @param array<string,mixed>|null $custom
     * @param array<string,int>        $defaults
     *
     * @return array<string,int>
     */
    private function mergeInts(?array $custom, array $defaults): array
    {
        if (!$custom) {
            return $defaults;
        }

        $merged = $defaults;
        foreach ($defaults as $key => $default) {
            if (isset($custom[$key]) && is_numeric($custom[$key])) {
                $merged[$key] = (int) $custom[$key];
            }
        }

        return $merged;
    }
}
