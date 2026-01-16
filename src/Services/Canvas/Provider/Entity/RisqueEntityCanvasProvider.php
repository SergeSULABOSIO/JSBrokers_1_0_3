<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\ConditionPartage;
use App\Entity\NotificationSinistre;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class RisqueEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Risque::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Risque",
                "icone" => "mdi:shield-alert-outline",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Risque [[*nomComplet]] (Code: [[code]]).",
                    " Branche: [[brancheString]].",
                    " Description: [[description]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "code", "intitule" => "Code", "type" => "Texte"],
                ["code" => "nomComplet", "intitule" => "Nom Complet", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "pourcentageCommissionSpecifiqueHT", "intitule" => "Com. Spécifique (%)", "type" => "Nombre", "unite" => "%"],
                ["code" => "imposable", "intitule" => "Imposable", "type" => "Booleen"],
                ["code" => "conditionPartage", "intitule" => "Condition de Partage", "type" => "Relation", "targetEntity" => ConditionPartage::class, "displayField" => "nom"],
                ["code" => "pistes", "intitule" => "Pistes", "type" => "Collection", "targetEntity" => Piste::class, "displayField" => "nom"],
                ["code" => "notificationSinistres", "intitule" => "Sinistres", "type" => "Collection", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
            ], $this->getSpecificIndicators(), $this->canvasHelper->getGlobalIndicatorsCanvas("Risque"))
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "brancheString", "intitule" => "Branche", "type" => "Texte", "format" => "Texte", "description" => "Branche d'assurance (IARD ou Vie)."],
            ["code" => "nombrePistes", "intitule" => "Nb. Pistes", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de pistes associées à ce risque."],
            ["code" => "nombreSinistres", "intitule" => "Nb. Sinistres", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de sinistres déclarés pour ce risque."],
            ["code" => "nombrePolices", "intitule" => "Nb. Polices", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de polices actives pour ce risque."],
        ];
    }
}