<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\ModelePieceSinistre;
use App\Services\Canvas\Provider\List\ListCanvasProviderInterface;

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
                "titre_colonne" => "Modèles de Pièces Sinistre",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "modele-piece"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "description", "attribut_taille_max" => 50],
                    ["attribut_prefixe" => "Statut: ", "attribut_code" => "statutObligation"],
                ],
            ],
            "colonnes_numeriques" => [],
        ];
    }
}