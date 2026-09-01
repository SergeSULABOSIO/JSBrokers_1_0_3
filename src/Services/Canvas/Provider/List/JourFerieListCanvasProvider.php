<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\JourFerie;

class JourFerieListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === JourFerie::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Jours fériés",
                "texte_principal" => ["attribut_code" => "libelle", "icone" => "fluent-mdl2:date-time-mirrored"],
                "textes_secondaires" => [
                    ["attribut_code" => "dateLibelle"],
                    ["attribut_prefixe" => "Exercice ", "attribut_code" => "exercice"],
                ],
            ],
            "colonnes_numeriques" => [],
        ];
    }
}
