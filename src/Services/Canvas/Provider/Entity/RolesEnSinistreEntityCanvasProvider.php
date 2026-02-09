<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Invite;
use App\Entity\RolesEnSinistre;
use App\Services\Canvas\CanvasHelper;

class RolesEnSinistreEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnSinistre::class;
    }

    public function getCanvas(): array
    {
        $accessFields = [
            'accessTypePiece' => 'Pièces',
            'accessNotification' => 'Sinistres',
            'accessReglement' => 'Règlements',
        ];

        $calculatedIndicators = [];
        foreach ($accessFields as $fieldCode => $label) {
            $calculatedIndicators[] = [
                "code" => $fieldCode . "String",
                "intitule" => "Accès " . $label,
                "type" => "Calcul",
                "format" => "Texte",
                "fonction" => "Role_getAccessString",
                "params" => [$fieldCode],
                "description" => "Permissions sur les " . strtolower($label) . "."
            ];
        }

        return [
            "parametres" => [
                "description" => "Rôle en Sinistre",
                "icone" => "action:role",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Rôle Sinistre: [[*nom]].",
                    " Assigné à: [[invite]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "invite", "intitule" => "Collaborateur", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "email"],
                [
                    "code" => "inviteNom",
                    "intitule" => "Nom Collaborateur",
                    "type" => "Calcul",
                    "format" => "Texte",
                    "description" => "Nom du collaborateur assigné à ce rôle."
                ]
            ], $calculatedIndicators, $this->canvasHelper->getGlobalIndicatorsCanvas("RolesEnSinistre"))
        ];
    }
}