<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\RolesEnFinance;

class RolesEnFinanceListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnFinance::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Rôles en Finance",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:finance"],
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Invité: ", "attribut_code" => "invite"],
                ],
            ],
        ];
    }
}