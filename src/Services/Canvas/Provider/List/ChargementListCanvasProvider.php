<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Chargement;

class ChargementListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Chargement::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Types de Chargement",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:cog-transfer"],
                "textes_secondaires" => [
                    ["attribut_code" => "description", "attribut_taille_max" => 50],
                ],
            ],
        ];
    }
}