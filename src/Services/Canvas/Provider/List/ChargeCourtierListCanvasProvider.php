<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\ChargeCourtier;

class ChargeCourtierListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ChargeCourtier::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Charges",
                "texte_principal" => ["attribut_code" => "libelle", "icone" => "tabler:receipt-tax"],
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Code : ", "attribut_code" => "code"],
                    ["attribut_prefixe" => "Compte : ", "attribut_code" => "compteOhadaFull", "attribut_type" => "calcul"],
                    ["attribut_code" => "profilCharge", "attribut_type" => "calcul"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Budget mensuel",
                    "attribut_code" => "montantBudgeteMensuelFloat",
                    "attribut_type" => "calcul",
                    "attribut_format" => "Monetaire",
                ],
            ],
        ];
    }
}
