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
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "code", "intitule" => "Code", "type" => "Texte"],
                ["code" => "tauxusd", "intitule" => "Taux USD", "type" => "Nombre"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Configuration", "code" => "fonctionString", "intitule" => "Fonction", "type" => "Texte", "description" => "Rôle de la monnaie dans le système."],
            ["group" => "Configuration", "code" => "localeString", "intitule" => "Locale", "type" => "Texte", "description" => "Indique si c'est la monnaie locale."],
            ["group" => "Analyse Taux", "code" => "tauxInverse", "intitule" => "Taux Inverse", "type" => "Nombre", "format" => "Nombre", "description" => "Valeur de 1 USD dans cette monnaie."],
            ["group" => "Analyse Taux", "code" => "statutTaux", "intitule" => "Statut Taux", "type" => "Texte", "description" => "Comparaison par rapport au pivot."],
            ["group" => "Affichage", "code" => "formatExemple", "intitule" => "Exemple", "type" => "Texte", "description" => "Exemple de formatage."],
            ["group" => "Affichage", "code" => "typeDevise", "intitule" => "Type", "type" => "Texte", "description" => "Classification de la devise."],
        ];
    }
}