<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\DepenseCourtier;

class DepenseCourtierListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === DepenseCourtier::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Dépenses",
                "texte_principal" => ["attribut_code" => "ligne_principale", "attribut_type" => "calcul", "icone" => "hugeicons:dollar-send-02"],
                "textes_secondaires" => [
                    ["attribut_code" => "ligne_secondaire", "attribut_type" => "calcul"],
                    ["attribut_prefixe" => "Statut : ", "attribut_code" => "statutLabel", "attribut_type" => "calcul"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant TTC",
                    "attribut_code" => "montantTtc",
                    "attribut_type" => "calcul",
                    "attribut_format" => "Monetaire",
                ],
                [
                    "titre_colonne" => "TVA déductible",
                    "attribut_code" => "tvaDeductible",
                    "attribut_type" => "calcul",
                    "attribut_format" => "Monetaire",
                ],
            ],
        ];
    }
}
