<?php

namespace App\Services\Canvas;

use App\Constantes\Constante;
use App\Entity\Assureur;
use App\Entity\AutoriteFiscale;
use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Contact;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\PieceSinistre;
use App\Entity\Tache;
use App\Entity\Tranche;
use App\Services\ServiceMonnaies;
use App\Services\Canvas\CalculationProvider;

class NumericCanvasProvider
{
    public function __construct() {}

    public function getAttributesAndValues($object): array
    {
        if ($object instanceof NotificationSinistre) {
            return array_merge([
                "dommageAvantEvaluation" => [
                    "description" => "Dommages (av. éval.)",
                    "value" => ($object->getDommage() ?? 0) * 100,
                ],
                'dommageApresEvaluation' => [
                    "description" => "Dommages (ap. éval.)",
                    "value" => ($object->getEvaluationChiffree() ?? 0) * 100,
                ],
                'franchise' => [
                    "description" => "Franchise",
                    "value" => ($this->getFranchiseForNotificationSinistre($object) ?? 0) * 100,
                ],
            ], $this->getCalculatedIndicatorsNumericAttributes($object));
        }
        if ($object instanceof Bordereau) {
            return array_merge([
                "montantTTC" => [
                    "description" => "Montant TTC",
                    "value" => ($object->getMontantTTC() ?? 0) * 100,
                ],
            ], $this->getCalculatedIndicatorsNumericAttributes($object));
        }
        if ($object instanceof OffreIndemnisationSinistre) {
            return array_merge([
                "franchiseAppliquee" => [
                    "description" => "Franchise",
                    "value" => ($object->getFranchiseAppliquee() ?? 0) * 100,
                ],
            ], $this->getCalculatedIndicatorsNumericAttributes($object));
        }
        if ($object instanceof ChargementPourPrime) {
            return array_merge([
                "montantFlatExceptionel" => [
                    "description" => "Montant",
                    "value" => ($object->getMontantFlatExceptionel() ?? 0) * 100,
                ],
            ], $this->getCalculatedIndicatorsNumericAttributes($object));
        }
        if ($object instanceof Assureur) {
            return $this->getCalculatedIndicatorsNumericAttributes($object);
        }
        if ($object instanceof Avenant) {
            return $this->getCalculatedIndicatorsNumericAttributes($object);
        }
        if ($object instanceof Client) {
            return $this->getCalculatedIndicatorsNumericAttributes($object);
        }
        if ($object instanceof Cotation) {
            return $this->getCalculatedIndicatorsNumericAttributes($object);
        }
        if ($object instanceof AutoriteFiscale) {
            return $this->getCalculatedIndicatorsNumericAttributes($object);
        }
        if ($object instanceof Chargement) {
            return $this->getCalculatedIndicatorsNumericAttributes($object);
        }
        if ($object instanceof Client) {
            return $this->getCalculatedIndicatorsNumericAttributes($object);
        }
        if ($object instanceof ConditionPartage) {
            return $this->getCalculatedIndicatorsNumericAttributes($object);
        }
        if ($object instanceof Contact) {
            return $this->getCalculatedIndicatorsNumericAttributes($object);
        }
        if ($object instanceof Document) {
            return $this->getCalculatedIndicatorsNumericAttributes($object);
        }
        if ($object instanceof Entreprise) {
            return $this->getCalculatedIndicatorsNumericAttributes($object);
        }
        if ($object instanceof Feedback) {
            return $this->getCalculatedIndicatorsNumericAttributes($object);
        }
        if ($object instanceof Tache) {
            return $this->getCalculatedIndicatorsNumericAttributes($object);
        }
        if ($object instanceof Tranche) {
            return $this->getCalculatedIndicatorsNumericAttributes($object);
        }
        return [];
    }

    private function getFranchiseForNotificationSinistre(NotificationSinistre $sinistre): float
    {
        $montant = 0;
        if ($sinistre != null) {
            foreach ($sinistre->getOffreIndemnisationSinistres() as $offre_indemnisation) {
                $montant += $offre_indemnisation->getFranchiseAppliquee();
            }
        }
        return $montant;
    }

    public function getAttributesAndValuesForCollection($data): array
    {
        $numericValues = [];
        // NOUVEAU : Si les données sont vides, on retourne un objet vide (et non un tableau)
        // pour éviter une erreur de type dans le contrôleur Stimulus `list-manager`.
        if (empty($data)) {
            return $numericValues; // On retourne un tableau vide pour respecter le type de retour "array".
        }

        foreach ($data as $entity) {
            $numericValues[$entity->getId()] = $this->getAttributesAndValues($entity);
        }
        return $numericValues;
    }

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
