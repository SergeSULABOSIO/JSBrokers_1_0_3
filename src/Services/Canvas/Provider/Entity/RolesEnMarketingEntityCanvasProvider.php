<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Invite;
use App\Entity\RolesEnMarketing;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class RolesEnMarketingEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnMarketing::class;
    }

    public function getCanvas(): array
    {
        $accessFields = [
            'accessPiste' => 'Pistes',
            'accessTache' => 'Tâches',
            'accessFeedback' => 'Feedbacks',
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
                "description" => "Rôle en Marketing",
                "icone" => "mdi:bullhorn-outline",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Rôle Marketing: [[*nom]].",
                    " Assigné à: [[invite]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "invite", "intitule" => "Collaborateur", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "email"],
            ], $calculatedIndicators, $this->canvasHelper->getGlobalIndicatorsCanvas("RolesEnMarketing"))
        ];
    }
}