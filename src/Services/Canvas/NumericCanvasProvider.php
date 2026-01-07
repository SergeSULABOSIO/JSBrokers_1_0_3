<?php

namespace App\Services\Canvas;

use App\Constantes\Constante;
use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Contact;
use App\Entity\Cotation;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\PieceSinistre;
use App\Entity\Tache;
use App\Services\Canvas\CalculationProvider;

class NumericCanvasProvider
{
    public function __construct(
        private Constante $constante,
        private CalculationProvider $calculationProvider
        )
    { 
    }

    public function getAttributesAndValues($object): array
    {
        if ($object instanceof NotificationSinistre) {
            return [
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
                    "value" => ($this->calculationProvider->Notification_Sinistre_getFranchise($object) ?? 0) * 100,
                ],
                "compensationTotale" => [
                    "description" => "Compensation totale",
                    "value" => ($this->calculationProvider->Notification_Sinistre_getCompensation($object) ?? 0) * 100,
                ],
                "compensationVersee" => [
                    "description" => "Compensation versée",
                    "value" => ($this->calculationProvider->Notification_Sinistre_getCompensationVersee($object) ?? 0) * 100,
                ],
                "compensationDue" => [
                    "description" => "Compensation due",
                    "value" => ($this->calculationProvider->Notification_Sinistre_getSoldeAVerser($object) ?? 0) * 100,
                ],
            ];
        }

        // --- AJOUT : Logique pour Assureur ---
        if ($object instanceof Assureur) {
            return [
                "montant_commission_ttc" => [
                    "description" => "Commissions TTC",
                    "value" => ($this->constante->Assureur_getMontant_commission_ttc($object, -1, false) ?? 0) * 100,
                ],
                "montant_commission_ttc_solde" => [
                    "description" => "Solde Commissions",
                    "value" => ($this->constante->Assureur_getMontant_commission_ttc_solde($object, -1, false) ?? 0) * 100,
                ],
                "montant_prime_payable_par_client_solde" => [
                    "description" => "Solde Primes Clients",
                    "value" => ($this->constante->Assureur_getMontant_prime_payable_par_client_solde($object) ?? 0) * 100,
                ],
            ];
        }

        if ($object instanceof Avenant) {
            return [
                "primeTTC" => [
                    "description" => "Prime TTC",
                    "value" => ($this->constante->Avenant_getPrimeTTC($object) ?? 0) * 100,
                ],
                "commissionTTC" => [
                    "description" => "Commission TTC",
                    "value" => ($this->constante->Avenant_getCommissionTTC($object, -1, false) ?? 0) * 100,
                ],
            ];
        }

        if ($object instanceof Bordereau) {
            return [
                "montantTTC" => [
                    "description" => "Montant TTC",
                    "value" => ($object->getMontantTTC() ?? 0) * 100,
                ],
            ];
        }

        if ($object instanceof Client) {
            return [
                "montant_commission_ttc" => [
                    "description" => "Commissions TTC",
                    "value" => ($this->constante->Client_getMontant_commission_ttc($object, -1, false) ?? 0) * 100,
                ],
                "montant_commission_ttc_solde" => [
                    "description" => "Solde Commissions",
                    "value" => ($this->constante->Client_getMontant_commission_ttc_solde($object, -1, false) ?? 0) * 100,
                ],
                "montant_prime_payable_par_client_solde" => [
                    "description" => "Solde Primes",
                    "value" => ($this->constante->Client_getMontant_prime_payable_par_client_solde($object) ?? 0) * 100,
                ],
            ];
        }

        if ($object instanceof Cotation) {
            return [
                "primeTTC" => [
                    "description" => "Prime TTC",
                    "value" => ($this->constante->Cotation_getMontant_prime_payable_par_client($object) ?? 0) * 100,
                ],
                "commissionTTC" => [
                    "description" => "Commission TTC",
                    "value" => ($this->constante->Cotation_getMontant_commission_ttc($object, -1, false) ?? 0) * 100,
                ],
            ];
        }

        // --- AJOUT : Logique pour OffreIndemnisationSinistre ---
        if ($object instanceof OffreIndemnisationSinistre) {
            return [
                "montantPayable" => [
                    "description" => "Montant Payable",
                    "value" => ($object->getMontantPayable() ?? 0) * 100,
                ],
                "franchiseAppliquee" => [
                    "description" => "Franchise",
                    "value" => ($object->getFranchiseAppliquee() ?? 0) * 100,
                ],
                "compensationVersee" => [
                    "description" => "Comp. versée",
                    "value" => ($this->calculationProvider->Offre_Indemnisation_getCompensationVersee($object) ?? 0) * 100,
                ],
                "compensationAVersee" => [
                    "description" => "Solde à verser",
                    "value" => ($this->calculationProvider->Offre_Indemnisation_getSoldeAVerser($object) ?? 0) * 100,
                ],
            ];
        }

        if ($object instanceof ChargementPourPrime) {
            return [
                "montantFlatExceptionel" => [
                    "description" => "Montant",
                    "value" => ($object->getMontantFlatExceptionel() ?? 0) * 100,
                ],
            ];
        }


        if ($object instanceof Contact || $object instanceof PieceSinistre || $object instanceof Tache) {
            // Ces entités n'ont pas de valeurs numériques à totaliser.
            return [];
        }

        return [];
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
}