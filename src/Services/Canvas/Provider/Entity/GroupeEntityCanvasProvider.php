<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Groupe;

class GroupeEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Groupe::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Groupe de clients",
                "icone" => "mdi:account-group-outline",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Groupe [[nom]].",
                    " [[description]]."
                ]
            ],
            "liste" => [
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "entreprise", "intitule" => "Entreprise", "type" => "Relation", "targetEntity" => Entreprise::class, "displayField" => "nom"],
                ["code" => "clients", "intitule" => "Clients", "type" => "Collection", "targetEntity" => Client::class, "displayField" => "nom"],
                ["code" => "nombreClients", "intitule" => "Nb. Clients", "type" => "Entier", "format" => "Nombre", "description" => "Nombre total de clients dans ce groupe."],
                ["code" => "nombrePolices", "intitule" => "Nb. Polices", "type" => "Entier", "format" => "Nombre", "description" => "Nombre total de polices pour les clients de ce groupe."],
                ["code" => "nombreSinistres", "intitule" => "Nb. Sinistres", "type" => "Entier", "format" => "Nombre", "description" => "Nombre total de sinistres pour les clients de ce groupe."],
            ]
        ];
    }
}