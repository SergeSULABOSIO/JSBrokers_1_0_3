<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Portefeuille;

class PortefeuilleListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Portefeuille::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Portefeuilles",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:briefcase-account"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "gestionnaire", "attribut_label" => "Gestionnaire : "],
                    ["attribut_code" => "nombreClients", "attribut_label" => "Clients : "],
                ],
            ],
            "colonnes_numeriques" => [],
        ];
    }
}
