<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\RolesEnProduction;

class RolesEnProductionListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnProduction::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Rôles en Production",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "role"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Collaborateur: ", "attribut_code" => "inviteNom"],
                ],
            ],
            "colonnes_numeriques" => [],
        ];
    }
}