<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\RolesEnAdministration;

class RolesEnAdministrationListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnAdministration::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Rôles en Administration",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:shield-account"],
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Invité: ", "attribut_code" => "invite"],
                ],
            ],
        ];
    }
}