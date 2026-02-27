<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Tache;

class TacheListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tache::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Tâches",
                "texte_principal" => ["attribut_code" => "descriptionText", "icone" => "tache"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Statut: ", "attribut_code" => "statutExecution"],
                    ["attribut_prefixe" => "Pour: ", "attribut_code" => "executor"],
                    ["attribut_prefixe" => "Échéance: ", "attribut_code" => "toBeEndedAt", "attribut_type" => "date"],
                    ["attribut_code" => "contexteTache"],
                ],
            ],
        ];
    }
}