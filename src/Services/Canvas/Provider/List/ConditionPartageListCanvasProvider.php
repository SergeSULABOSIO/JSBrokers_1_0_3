<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\ConditionPartage;

class ConditionPartageListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ConditionPartage::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Conditions de Partage",
                "texte_principal" => [
                    "attribut_code" => "nom", 
                    "icone" => "mdi:share-variant"
                    ],
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Taux: ", "attribut_code" => "taux", "attribut_type" => "pourcentage"],
                ],
            ],
        ];
    }
}