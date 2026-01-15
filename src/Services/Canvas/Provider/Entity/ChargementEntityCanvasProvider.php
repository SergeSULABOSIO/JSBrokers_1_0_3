<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\TypeRevenu;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class ChargementEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Chargement::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Type de chargement",
                "icone" => "mdi:cog-transfer",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Type de chargement : [[*nom]].",
                    " Description : <em>« [[description]] »</em>."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "fonction", "intitule" => "Fonction", "type" => "Texte"], // Maybe a calculated field to get the text? For now, it's just the int.
                ["code" => "chargementPourPrimes", "intitule" => "Utilisations (Primes)", "type" => "Collection", "targetEntity" => ChargementPourPrime::class, "displayField" => "nom"],
                ["code" => "typeRevenus", "intitule" => "Utilisations (Revenus)", "type" => "Collection", "targetEntity" => TypeRevenu::class, "displayField" => "nom"],
            ], $this->canvasHelper->getGlobalIndicatorsCanvas("Chargement"))
        ];
    }
}