<?php

namespace App\Enum;

/**
 * @file Mode de suivi de l'atteinte d'un objectif d'évaluation.
 * @description MANUEL : la valeur atteinte est saisie par le super-admin lors des
 * revues. AUTO : la valeur est recalculée à la volée depuis les données de la
 * plateforme par EvaluationMetricProvider (uniquement pour les métriques réellement
 * attribuables à un collaborateur).
 */
enum ObjectifMode: string
{
    case MANUEL = 'manuel';
    case AUTO   = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::MANUEL => 'Suivi manuel (saisie en revue)',
            self::AUTO   => 'Mesure automatique (données système)',
        };
    }
}
