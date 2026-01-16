<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Document;
use App\Entity\Invite;
use App\Entity\ModelePieceSinistre;
use App\Entity\NotificationSinistre;
use App\Entity\PieceSinistre;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class PieceSinistreEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === PieceSinistre::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Pièce de Sinistre",
                "icone" => "mdi:file-document",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Pièce: [[*description]].",
                    " Reçue le [[receivedAt]] de [[fourniPar]].",
                    " Type: [[type]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "type", "intitule" => "Type de Pièce", "type" => "Relation", "targetEntity" => ModelePieceSinistre::class, "displayField" => "nom"],
                ["code" => "receivedAt", "intitule" => "Reçue le", "type" => "Date"],
                ["code" => "fourniPar", "intitule" => "Fourni par", "type" => "Texte"],
                ["code" => "invite", "intitule" => "Enregistré par", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "email"],
                ["code" => "notificationSinistre", "intitule" => "Sinistre", "type" => "Relation", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators(), $this->canvasHelper->getGlobalIndicatorsCanvas("PieceSinistre"))
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "agePiece", "intitule" => "Âge", "type" => "Texte", "format" => "Texte", "description" => "Nombre de jours depuis la réception de la pièce."],
            ["code" => "nombreDocuments", "intitule" => "Nb. Documents", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de documents joints à cette pièce."],
        ];
    }
}