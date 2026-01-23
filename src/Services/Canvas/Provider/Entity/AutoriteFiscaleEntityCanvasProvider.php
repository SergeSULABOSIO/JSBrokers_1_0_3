<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\AutoriteFiscale;
use App\Entity\Note;
use App\Entity\Taxe;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class AutoriteFiscaleEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === AutoriteFiscale::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Autorité Fiscale",
                "icone" => "mdi:bank",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "L'autorité fiscale [[*nom]] ([[abreviation]]) est responsable de la collecte des taxes."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "abreviation", "intitule" => "Abréviation", "type" => "Texte"],
                ["code" => "taxe", "intitule" => "Taxe Associée", "type" => "Relation", "targetEntity" => Taxe::class, "displayField" => "nom"],
                ["code" => "notes", "intitule" => "Notes de débit/crédit", "type" => "Collection", "targetEntity" => Note::class, "displayField" => "reference"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [];
    }
}