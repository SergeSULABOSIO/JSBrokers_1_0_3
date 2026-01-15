<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Avenant;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class AvenantEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Avenant::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Avenant",
                "icone" => "mdi:file-document-edit",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Avenant n°[[*numero]] de la police [[referencePolice]].",
                    " Période de couverture du [[startingAt]] au [[endingAt]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "referencePolice", "intitule" => "Réf. Police", "type" => "Texte"],
                ["code" => "numero", "intitule" => "Numéro", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "startingAt", "intitule" => "Date d'effet", "type" => "Date"],
                ["code" => "endingAt", "intitule" => "Date d'échéance", "type" => "Date"],
                ["code" => "cotation", "intitule" => "Cotation", "type" => "Relation", "targetEntity" => Cotation::class, "displayField" => "nom"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators(), $this->canvasHelper->getGlobalIndicatorsCanvas("Avenant"))
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "dureeCouverture", "intitule" => "Durée de couverture", "type" => "Texte", "format" => "Texte", "description" => "Durée totale de la couverture de l'avenant en jours."],
            ["code" => "joursRestants", "intitule" => "Jours restants", "type" => "Texte", "format" => "Texte", "description" => "Nombre de jours restants avant l'échéance de l'avenant."],
            ["code" => "ageAvenant", "intitule" => "Âge de l'avenant", "type" => "Texte", "format" => "Texte", "description" => "Nombre de jours écoulés depuis la création de l'avenant."],
            ["code" => "statutRenouvellement", "intitule" => "Statut", "type" => "Texte", "format" => "Texte", "description" => "Statut actuel du renouvellement de l'avenant."],
        ];
    }
}