<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Taxe;

class TaxeListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Taxe::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Taxes",
                "texte_principal" => ["attribut_code" => "code", "icone" => "mdi:percent-box"],
                "textes_secondaires" => [
                    ["attribut_code" => "description", "attribut_taille_max" => 50],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Taux IARD",
                    "attribut_unité" => "%",
                    "attribut_code" => "tauxIARD",
                    "attribut_type" => "nombre",
                ],
                ["titre_colonne" => "Taux VIE", "attribut_unité" => "%", "attribut_code" => "tauxVIE", "attribut_type" => "nombre"],
            ],
        ];
    }
}