<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Classeur;
use App\Entity\Document;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class ClasseurEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Classeur::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Classeur",
                "icone" => "classeur",
                'background_image' => '/images/fitures/classeur.png',
                'description_template' => [
                    "Classeur: [[*nom]].",
                    " <em>« [[description]] »</em>"
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators()) // Note: This collection is not directly related to global indicators.
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [];
    }
}