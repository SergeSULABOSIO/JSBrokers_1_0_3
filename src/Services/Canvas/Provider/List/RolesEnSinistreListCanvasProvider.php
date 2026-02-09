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