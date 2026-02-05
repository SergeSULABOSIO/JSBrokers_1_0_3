<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Classeur;

class ClasseurListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Classeur::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Classeurs",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "classeur"],
                "textes_secondaires" => [["attribut_code" => "description", "attribut_taille_max" => 50]],
            ],
        ];
    }
}