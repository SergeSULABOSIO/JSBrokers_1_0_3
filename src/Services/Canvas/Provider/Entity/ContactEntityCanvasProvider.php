<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Client;
use App\Entity\Contact;
use App\Entity\NotificationSinistre;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class ContactEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Contact::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Contact",
                "icone" => "contact",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Contact [[*nom]] ([[fonction]]).",
                    " Email: [[email]] / Téléphone: [[telephone]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "telephone", "intitule" => "Téléphone", "type" => "Texte"],
                ["code" => "email", "intitule" => "Email", "type" => "Texte"],
                ["code" => "fonction", "intitule" => "Fonction", "type" => "Texte"],
                ["code" => "client", "intitule" => "Client", "type" => "Relation", "targetEntity" => Client::class, "displayField" => "nom"],
                ["code" => "notificationSinistre", "intitule" => "Sinistre", "type" => "Relation", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            [
                "code" => "type_string",
                "intitule" => "Type",
                "type" => "Calcul",
                "format" => "Texte",
                "fonction" => "Contact_getTypeString",
                "description" => "Le type de contact (Production, Sinistre, etc.)."
            ]
        ];
    }
}