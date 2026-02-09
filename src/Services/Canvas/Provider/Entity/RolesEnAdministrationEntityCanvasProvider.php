<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Invite;
use App\Entity\RolesEnAdministration;
use App\Services\Canvas\CanvasHelper;

class RolesEnAdministrationEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnAdministration::class;
    }

    public function getCanvas(): array
    {
        $accessFields = [
            'accessDocument' => 'Documents',
            'accessClasseur' => 'Classeurs',
            'accessInvite' => 'Collaborateurs',
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

        $calculatedIndicators[] = [
            "code" => "inviteNom",
            "intitule" => "Nom Collaborateur",
            "type" => "Calcul",
            "format" => "Texte",
            "description" => "Nom du collaborateur assigné à ce rôle."
        ];

        return [
            "parametres" => [
                "description" => "Rôle en Administration",
                "icone" => "action:role",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Rôle Administration: [[*nom]].",
                    " Assigné à: [[invite]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "invite", "intitule" => "Collaborateur", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "email"],
            ], $calculatedIndicators, $this->canvasHelper->getGlobalIndicatorsCanvas("RolesEnAdministration"))
        ];
    }
}