<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Groupe;

class GroupeListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Groupe::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Groupes de clients",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:account-multiple"],
                "textes_secondaires" => [
                    ["attribut_code" => "description", "attribut_taille_max" => 50],
                ],
            ],
        ];
    }
}