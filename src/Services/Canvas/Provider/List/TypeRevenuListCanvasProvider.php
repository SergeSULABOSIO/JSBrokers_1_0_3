<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\TypeRevenu;

class TypeRevenuListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === TypeRevenu::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Types de Revenu",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:cash-register"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Redevable: ", "attribut_code" => "redevable_string"],
                    ["attribut_prefixe" => "Partagé: ", "attribut_code" => "shared_string"],
                ],
            ],
        ];
    }
}