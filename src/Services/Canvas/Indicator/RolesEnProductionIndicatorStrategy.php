<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\RolesEnProduction;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class RolesEnProductionIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnProduction::class;
    }

    public function calculate(object $entity): array
    {
        /** @var RolesEnProduction $entity */
        $invite = $entity->getInvite();
        $inviteNom = $invite ? $invite->getNom() : 'N/A';
        
        $indicateurs = [
            'inviteNom' => $inviteNom,
        ];
        
        $accessFields = [
            'accessGroupe', 'accessClient', 'accessAssureur', 'accessContact',
            'accessRisque', 'accessAvenant', 'accessPartenaire', 'accessCotation'
        ];
        
        foreach ($accessFields as $field) {
            if (method_exists($entity, 'get' . ucfirst($field))) {
                $indicateurs[$field . 'String'] = $this->calculationHelper->getRoleAccessString($entity, [$field]);
            }
        }

        return $indicateurs;
    }
}