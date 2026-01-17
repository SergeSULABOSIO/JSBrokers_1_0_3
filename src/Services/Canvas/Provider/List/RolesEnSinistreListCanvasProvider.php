<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\RolesEnSinistre;

class RolesEnSinistreListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnSinistre::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Rôles en Sinistre",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:car-wrench"],
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Invité: ", "attribut_code" => "invite"],
                ],
            ],
        ];
    }
}