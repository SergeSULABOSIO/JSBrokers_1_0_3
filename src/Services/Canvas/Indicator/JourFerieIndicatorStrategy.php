<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\JourFerie;

/**
 * Le jour de la SEMAINE, en plus de la date.
 *
 * « Le 30/06/2026 » ne dit pas grand-chose ; « mardi 30 juin 2026 » se vérifie d'un coup
 * d'œil — et c'est ainsi qu'on repère une date saisie de travers, avant qu'elle ne fausse
 * le décompte d'une demande.
 */
class JourFerieIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    private const JOURS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === JourFerie::class;
    }

    public function calculate(object $entity): array
    {
        /** @var JourFerie $entity */
        $date = $entity->getDate();

        return [
            'dateLibelle' => $date === null
                ? 'Date non renseignée'
                : sprintf('%s %s', self::JOURS[(int) $date->format('N')] ?? '', $date->format('d/m/Y')),
        ];
    }
}
