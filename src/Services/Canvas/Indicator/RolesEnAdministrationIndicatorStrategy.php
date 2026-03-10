<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\RolesEnAdministration;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class RolesEnAdministrationIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnAdministration::class;
    }

    public function calculate(object $entity): array
    {
        /** @var RolesEnAdministration $entity */
        $invite = $entity->getInvite();
        $inviteNom = $invite ? $invite->getNom() : 'N/A';
        
        $indicateurs = [
            'inviteNom' => $inviteNom,
        ];
        
        $accessFields = ['accessDocument', 'accessClasseur', 'accessInvite'];
        
        foreach ($accessFields as $field) {
            if (method_exists($entity, 'get' . ucfirst($field))) {
                $indicateurs[$field . 'String'] = $this->calculationHelper->getRoleAccessString($entity, [$field]);
            }
        }

        return $indicateurs;
    }
}