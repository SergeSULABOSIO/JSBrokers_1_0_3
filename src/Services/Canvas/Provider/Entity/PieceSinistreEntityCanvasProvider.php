<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Invite;
use App\Entity\Document;
use App\Entity\PieceSinistre;
use App\Services\ServiceMonnaies;
use App\Entity\ModelePieceSinistre;
use App\Entity\NotificationSinistre;
use App\Services\Canvas\CanvasHelper;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.entity_canvas_provider')]
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
                "description" => "Pièce Sinistre",
                "icone" => "piece-sinistre",
                'background_image' => '/images/fitures/piecesinistre.png',
                'description_template' => ["Pièce: [[description]]. Reçue le [[receivedAt|date('d/m/Y')]]. Fournie par [[fourniPar]]."]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "type", "intitule" => "Type de pièce", "type" => "Relation", "targetEntity" => ModelePieceSinistre::class, "displayField" => "nom"],
                ["code" => "receivedAt", "intitule" => "Reçue le", "type" => "Date"],
                ["code" => "fourniPar", "intitule" => "Fournie par", "type" => "Texte"],
                ["code" => "invite", "intitule" => "Invité", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "nom"],
                ["code" => "notificationSinistre", "intitule" => "Notification Sinistre", "type" => "Relation", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
                ["code" => "documents", "intitule" => "Documents liés", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "agePiece", "intitule" => "Âge de la pièce", "type" => "Calcul", "format" => "Texte", "fonction" => "calculatePieceSinistreAge", "description" => "Âge de la pièce depuis sa date de réception."],
            ["code" => "typePieceNom", "intitule" => "Nom du type", "type" => "Calcul", "format" => "Texte", "fonction" => "getPieceSinistreTypeName", "description" => "Nom du modèle de pièce associé."],
            ["code" => "estObligatoire", "intitule" => "Obligatoire", "type" => "Calcul", "format" => "Texte", "fonction" => "getPieceSinistreEstObligatoire", "description" => "Indique si le modèle de pièce est obligatoire."],
        ];
    }
}