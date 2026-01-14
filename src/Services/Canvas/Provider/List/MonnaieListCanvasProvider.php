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
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:currency-usd"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "code"],
                    ["attribut_prefixe" => "Symbole: ", "attribut_code" => "symbole"],
                ],
            ],
        ];
    }
}