<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\PieceSinistre;
use App\Services\Canvas\Provider\List\ListCanvasProviderInterface;

class PieceSinistreListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === PieceSinistre::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Pièces de Sinistre",
                "texte_principal" => ["attribut_code" => "description", "icone" => "piece-sinistre", "attribut_taille_max" => 50],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Type: ", "attribut_code" => "type"],
                    ["attribut_prefixe" => "Reçu le: ", "attribut_code" => "receivedAt", "attribut_type" => "date"],
                ],
            ],
            "colonnes_numeriques" => [], // Important : Pas de colonnes numériques pour cette entité.
        ];
    }
}