<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Monnaie;
use App\Services\Canvas\CanvasHelper;

class MonnaieEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Monnaie::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Monnaie",
                "icone" => "monnaie",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Devise [[*nom]] ([[code]]).",
                    " Taux USD: [[tauxusd]].",
                    " Fonction: [[fonctionString]]."
                ]
            ],
            "liste" => [
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "code", "intitule" => "Code", "type" => "Texte"],
                ["code" => "tauxusd", "intitule" => "Taux USD", "type" => "Nombre"],
                ["code" => "fonctionString", "intitule" => "Fonction", "type" => "Texte"],
                ["code" => "localeString", "intitule" => "Locale", "type" => "Texte"],
            ]
        ];
    }
}