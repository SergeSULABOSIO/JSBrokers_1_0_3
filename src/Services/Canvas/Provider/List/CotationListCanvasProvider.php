<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Cotation;

class CotationListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Cotation::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Cotations",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:file-chart"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "assureur"],
                    ["attribut_prefixe" => "Piste: ", "attribut_code" => "piste"],
                ],
            ],
        ];
    }
}