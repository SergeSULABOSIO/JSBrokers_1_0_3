<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Client;

class ClientListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Client::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Clients",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:account-group"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "email"],
                    ["attribut_code" => "telephone"],
                ],
            ],
        ];
    }
}