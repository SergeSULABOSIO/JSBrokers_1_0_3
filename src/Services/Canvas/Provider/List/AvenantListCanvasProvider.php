<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Avenant;

class AvenantListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Avenant::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Avenants",
                "texte_principal" => [
                    "attribut_code" => "referencePolice",
                    "icone" => "mdi:file-document-edit",
                ],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Avt n°", "attribut_code" => "numero"],
                    ["attribut_prefixe" => "Effet: ", "attribut_code" => "startingAt", "attribut_type" => "date"],
                ],
            ],
        ];
    }
}