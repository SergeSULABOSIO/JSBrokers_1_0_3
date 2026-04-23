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
                    ["attribut_code" => "descriptionStripped", "attribut_taille_max" => 50],
                    ["attribut_prefixe" => "Redevable: ", "attribut_code" => "redevableString"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Taux IARD",
                    "attribut_unité" => "%",
                    "attribut_code" => "tauxIARDPercent",
                    "attribut_type" => "calcul",
                ],
                ["titre_colonne" => "Taux VIE", "attribut_unité" => "%", "attribut_code" => "tauxVIEPercent", "attribut_type" => "calcul"],
            ],
        ];
    }
}