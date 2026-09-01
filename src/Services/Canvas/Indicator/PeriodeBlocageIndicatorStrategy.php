<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\PeriodeBlocage;

/**
 * Une période de blocage se lit par ses DATES, et par le fait qu'elle soit encore active.
 * Une période désactivée qui ressemblerait à une période active ferait chercher longtemps
 * pourquoi un congé passe alors qu'il ne devrait pas.
 */
class PeriodeBlocageIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === PeriodeBlocage::class;
    }

    public function calculate(object $entity): array
    {
        /** @var PeriodeBlocage $entity */
        $libelle = sprintf(
            'Du %s au %s',
            $entity->getDateDebut()?->format('d/m/Y') ?? '?',
            $entity->getDateFin()?->format('d/m/Y') ?? '?',
        );

        return [
            'periodeLibelle' => $entity->isActif() ? $libelle : $libelle . ' · DÉSACTIVÉE',
        ];
    }
}
