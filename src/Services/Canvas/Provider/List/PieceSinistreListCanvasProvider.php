<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\PieceSinistre;

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
                "texte_principal" => ["attribut_code" => "description", "icone" => "codex:file"],
                "textes_secondaires" => [["attribut_prefixe" => "Reçu le: ", "attribut_code" => "receivedAt", "attribut_type" => "date"]],
            ],
        ];
    }
}