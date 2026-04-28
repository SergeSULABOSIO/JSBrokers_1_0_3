<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Operation;
use App\Entity\Bordereau;
use App\Services\ServiceMonnaies;

class OperationEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Operation::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Détails d'une opération de bordereau",
                "icone" => "operation", // Alias for a relevant icon
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Opération sur police [[referencePolice]] - Avenant [[numeroAvenant]].",
                    " Montant HT: [[montantHT]], Taxe: [[montantTaxe]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "referencePolice", "intitule" => "Réf. Police", "type" => "Texte"],
                ["code" => "numeroAvenant", "intitule" => "N° Avenant", "type" => "Texte"],
                ["code" => "montantHT", "intitule" => "Montant HT", "type" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "montantTaxe", "intitule" => "Montant Taxe", "type" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "bordereau", "intitule" => "Bordereau", "type" => "Relation", "targetEntity" => Bordereau::class, "displayField" => "reference"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Finances", "code" => "montantTTC", "intitule" => "Montant TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant Total TTC de l'opération."],
        ];
    }
}