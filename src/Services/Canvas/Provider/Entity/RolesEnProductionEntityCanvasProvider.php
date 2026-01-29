<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Invite;
use App\Entity\RolesEnProduction;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class RolesEnProductionEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnProduction::class;
    }

    public function getCanvas(): array
    {
        $accessFields = [
            'accessGroupe' => 'Groupes',
            'accessClient' => 'Clients',
            'accessAssureur' => 'Assureurs',
            'accessContact' => 'Contacts',
            'accessRisque' => 'Risques',
            'accessAvenant' => 'Avenants',
            'accessPartenaire' => 'Partenaires',
            'accessCotation' => 'Cotations',
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
                "description" => "Rôle en Production",
                "icone" => "action:role",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Rôle Production: [[*nom]].",
                    " Assigné à: [[invite]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "invite", "intitule" => "Collaborateur", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "email"],
            ], $calculatedIndicators, $this->canvasHelper->getGlobalIndicatorsCanvas("RolesEnProduction"))
        ];
    }
}