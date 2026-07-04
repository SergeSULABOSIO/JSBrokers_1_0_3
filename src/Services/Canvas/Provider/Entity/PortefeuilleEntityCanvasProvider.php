<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Client;
use App\Entity\Invite;
use App\Entity\Portefeuille;

class PortefeuilleEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Portefeuille::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Portefeuille client",
                "icone" => "portefeuille",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Portefeuille [[*nom]].",
                    " Gestionnaire de compte : [[gestionnaire]].",
                ],
            ],
            "liste" => [
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "gestionnaire", "intitule" => "Gestionnaire de compte", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "nom"],
                ["code" => "clients", "intitule" => "Clients", "type" => "Collection", "targetEntity" => Client::class, "displayField" => "nom"],
            ],
        ];
    }
}
