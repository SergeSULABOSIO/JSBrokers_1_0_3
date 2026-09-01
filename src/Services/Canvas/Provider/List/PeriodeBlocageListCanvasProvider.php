<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\PeriodeBlocage;

class PeriodeBlocageListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === PeriodeBlocage::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Périodes de blocage",
                "texte_principal" => ["attribut_code" => "libelle", "icone" => "gravity-ui:hand-stop"],
                "textes_secondaires" => [
                    ["attribut_code" => "periodeLibelle"],
                ],
            ],
            "colonnes_numeriques" => [],
        ];
    }
}
