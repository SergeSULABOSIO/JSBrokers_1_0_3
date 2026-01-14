<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Client;
use App\Services\ServiceMonnaies;

class ClientListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

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
            // La méthode getSharedNumericColumns sera gérée par le résolveur principal
            // pour éviter la duplication.
            "colonnes_numeriques" => [],
        ];
    }
}
