<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Monnaie;

class MonnaieListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Monnaie::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Monnaies",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "monnaie"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "code"],
                    ["attribut_prefixe" => "Taux USD: ", "attribut_code" => "tauxusd"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Taux USD",
                    "attribut_unité" => "",
                    "attribut_code" => "tauxusd",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}