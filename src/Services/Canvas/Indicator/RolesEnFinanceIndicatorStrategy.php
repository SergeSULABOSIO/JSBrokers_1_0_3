<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\RolesEnFinance;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class RolesEnFinanceIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnFinance::class;
    }

    public function calculate(object $entity): array
    {
        /** @var RolesEnFinance $entity */
        $invite = $entity->getInvite();
        $inviteNom = $invite ? $invite->getNom() : 'N/A';
        
        $indicateurs = [
            'inviteNom' => $inviteNom,
        ];
        
        $accessFields = [
            'accessMonnaie', 'accessCompteBancaire', 'accessTaxe', 'accessTypeRevenu',
            'accessTranche', 'accessTypeChargement', 'accessNote', 'accessPaiement',
            'accessBordereau', 'accessRevenu'
        ];
        
        foreach ($accessFields as $field) {
            if (method_exists($entity, 'get' . ucfirst($field))) {
                $indicateurs[$field . 'String'] = $this->calculationHelper->getRoleAccessString($entity, [$field]);
            }
        }

        return $indicateurs;
    }
}