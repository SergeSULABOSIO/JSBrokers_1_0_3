<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Invite;
use App\Entity\RolesEnSinistre;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class RolesEnSinistreEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnSinistre::class;
    }

    public function getCanvas(): array
    {
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
                ],
                [
                    "code" => "accessTypePieceString",
                    "intitule" => "Accès Pièces",
                    "type" => "Calcul",
                    "format" => "Texte",
                    "fonction" => "Role_getAccessString",
                    "params" => ["accessTypePiece"],
                    "description" => "Permissions sur les types de pièces."
                ],
                [
                    "code" => "accessNotificationString",
                    "intitule" => "Accès Sinistres",
                    "type" => "Calcul",
                    "format" => "Texte",
                    "fonction" => "Role_getAccessString",
                    "params" => ["accessNotification"],
                    "description" => "Permissions sur les déclarations de sinistre."
                ],
                [
                    "code" => "accessReglementString",
                    "intitule" => "Accès Règlements",
                    "type" => "Calcul",
                    "format" => "Texte",
                    "fonction" => "Role_getAccessString",
                    "params" => ["accessReglement"],
                    "description" => "Permissions sur les règlements de sinistre."
                ],
            ], $this->canvasHelper->getGlobalIndicatorsCanvas("RolesEnSinistre"))
        ];
    }
}