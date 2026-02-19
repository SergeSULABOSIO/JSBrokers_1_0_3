<?php

namespace App\Services\Canvas\Provider\Numeric;

trait CalculatedIndicatorsNumericProviderTrait
{
    /**
     * Returns an array of numeric attributes from CalculatedIndicatorsTrait.
     *
     * @param object $object An entity object that uses CalculatedIndicatorsTrait.
     * @return array
     */
    private function getCalculatedIndicatorsNumericAttributes(object $object): array
    {
        $attributes = [];
        $indicators = [
            'primeTotale' => ['description' => 'Prime Totale', 'is_percentage' => false],
            'primePayee' => ['description' => 'Prime Payée', 'is_percentage' => false],
            'primeSoldeDue' => ['description' => 'Solde Prime', 'is_percentage' => false],
            'montantTTC' => ['description' => 'Commission Totale', 'is_percentage' => false],
            'montant_paye' => ['description' => 'Commission Encaissée', 'is_percentage' => false],
            'solde_restant_du' => ['description' => 'Solde Commission', 'is_percentage' => false],
            'montantHT' => ['description' => 'Commission Nette', 'is_percentage' => false],
            'montantPur' => ['description' => 'Commission Pure', 'is_percentage' => false],
            'commissionPartageable' => ['description' => 'Assiette Partageable', 'is_percentage' => false],
            'primeNette' => ['description' => 'Prime Nette', 'is_percentage' => false],
            'reserve' => ['description' => 'Réserve Courtier', 'is_percentage' => false],
            'retroCommission' => ['description' => 'Rétrocommission Partenaire', 'is_percentage' => false],
            'retroCommissionReversee' => ['description' => 'Rétrocommission Payée', 'is_percentage' => false],
            'retroCommissionSolde' => ['description' => 'Solde Rétrocommission', 'is_percentage' => false],
            'taxeCourtierMontant' => ['description' => 'Taxe Courtier', 'is_percentage' => false],
            'taxeCourtierPayee' => ['description' => 'Taxe Courtier Payée', 'is_percentage' => false],
            'taxeCourtierSolde' => ['description' => 'Solde Taxe Courtier', 'is_percentage' => false],
            'taxeAssureurMontant' => ['description' => 'Taxe Assureur', 'is_percentage' => false],
            'taxeAssureurPayee' => ['description' => 'Taxe Assureur Payée', 'is_percentage' => false],
            'taxeAssureurSolde' => ['description' => 'Solde Taxe Assureur', 'is_percentage' => false],
            'indemnisationDue' => ['description' => 'Sinistre Payable', 'is_percentage' => false],
            'indemnisationVersee' => ['description' => 'Sinistre Payé', 'is_percentage' => false],
            'indemnisationSolde' => ['description' => 'Solde Sinistre', 'is_percentage' => false],
            'tauxSP' => ['description' => 'Taux de Sinistralité', 'is_percentage' => true],
            'tauxCommission' => ['description' => 'Taux de Commission', 'is_percentage' => true],
        ];

        foreach ($indicators as $code => $config) {
            // Check if the property exists on the object before trying to access it
            if (property_exists($object, $code)) {
                $value = $object->$code ?? 0;
                $item = [
                    "description" => $config['description'],
                    "value" => $value,
                ];
                if (!$config['is_percentage']) {
                    $item['value'] *= 100; // Convert to cents for non-percentage values
                } else {
                    $item['unit'] = "%"; // Add unit for percentages
                }
                $attributes[$code] = $item;
            }
        }

        return $attributes;
    }
}
