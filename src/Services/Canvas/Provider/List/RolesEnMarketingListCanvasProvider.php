<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\RolesEnMarketing;

class RolesEnMarketingListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnMarketing::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Rôles en Marketing",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:bullhorn-variant"],
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Invité: ", "attribut_code" => "invite"],
                ],
            ],
        ];
    }
}