<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\ModelePieceSinistre;

class ModelePieceSinistreListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ModelePieceSinistre::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Modèles de Pièces",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:file-check-outline"],
                "textes_secondaires" => [
                    ["attribut_code" => "description", "attribut_taille_max" => 50],
                ],
            ],
        ];
    }
}