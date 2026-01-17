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
            'prime_totale' => ['description' => 'Prime Totale', 'is_percentage' => false],
            'prime_totale_payee' => ['description' => 'Prime Payée', 'is_percentage' => false],
            'prime_totale_solde' => ['description' => 'Solde Prime', 'is_percentage' => false],
            'commission_totale' => ['description' => 'Commission Totale', 'is_percentage' => false],
            'commission_totale_encaissee' => ['description' => 'Commission Encaissée', 'is_percentage' => false],
            'commission_totale_solde' => ['description' => 'Solde Commission', 'is_percentage' => false],
            'commission_nette' => ['description' => 'Commission Nette', 'is_percentage' => false],
            'commission_pure' => ['description' => 'Commission Pure', 'is_percentage' => false],
            'commission_partageable' => ['description' => 'Assiette Partageable', 'is_percentage' => false],
            'prime_nette' => ['description' => 'Prime Nette', 'is_percentage' => false],
            'reserve' => ['description' => 'Réserve Courtier', 'is_percentage' => false],
            'retro_commission_partenaire' => ['description' => 'Rétrocommission Partenaire', 'is_percentage' => false],
            'retro_commission_partenaire_payee' => ['description' => 'Rétrocommission Payée', 'is_percentage' => false],
            'retro_commission_partenaire_solde' => ['description' => 'Solde Rétrocommission', 'is_percentage' => false],
            'taxe_courtier' => ['description' => 'Taxe Courtier', 'is_percentage' => false],
            'taxe_courtier_payee' => ['description' => 'Taxe Courtier Payée', 'is_percentage' => false],
            'taxe_courtier_solde' => ['description' => 'Solde Taxe Courtier', 'is_percentage' => false],
            'taxe_assureur' => ['description' => 'Taxe Assureur', 'is_percentage' => false],
            'taxe_assureur_payee' => ['description' => 'Taxe Assureur Payée', 'is_percentage' => false],
            'taxe_assureur_solde' => ['description' => 'Solde Taxe Assureur', 'is_percentage' => false],
            'sinistre_payable' => ['description' => 'Sinistre Payable', 'is_percentage' => false],
            'sinistre_paye' => ['description' => 'Sinistre Payé', 'is_percentage' => false],
            'sinistre_solde' => ['description' => 'Solde Sinistre', 'is_percentage' => false],
            'taux_sinistralite' => ['description' => 'Taux de Sinistralité', 'is_percentage' => true],
            'taux_de_commission' => ['description' => 'Taux de Commission', 'is_percentage' => true],
            'taux_de_retrocommission_effectif' => ['description' => 'Taux Rétro. Effectif', 'is_percentage' => true],
            'taux_de_paiement_prime' => ['description' => 'Taux Paiement Prime', 'is_percentage' => true],
            'taux_de_paiement_commission' => ['description' => 'Taux Encaissement Comm.', 'is_percentage' => true],
            'taux_de_paiement_retro_commission' => ['description' => 'Taux Paiement Rétro.', 'is_percentage' => true],
            'taux_de_paiement_taxe_courtier' => ['description' => 'Taux Paiement Taxe Courtier', 'is_percentage' => true],
            'taux_de_paiement_taxe_assureur' => ['description' => 'Taux Paiement Taxe Assureur', 'is_percentage' => true],
            'taux_de_paiement_sinistre' => ['description' => 'Taux Paiement Sinistre', 'is_percentage' => true],
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
