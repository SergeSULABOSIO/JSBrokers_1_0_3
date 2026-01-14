<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Risque;

class RisqueListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Risque::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Risques",
                "texte_principal" => ["attribut_code" => "nomComplet", "icone" => "mdi:hazard-lights"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Code: ", "attribut_code" => "code"],
                    ["attribut_prefixe" => "Branche: ", "attribut_code" => "branche_string"],
                ],
            ],
        ];
    }
}