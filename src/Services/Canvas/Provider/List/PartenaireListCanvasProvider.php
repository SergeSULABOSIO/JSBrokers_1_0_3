<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Partenaire;

class PartenaireListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Partenaire::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Partenaires",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "partenaire"],
                "textes_secondaires" => [
                    ["attribut_code" => "email"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Part (%)",
                    "attribut_unité" => "%",
                    "attribut_code" => "part",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}