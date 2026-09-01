<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\ParametresConge;

class ParametresCongeListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ParametresConge::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Paramètres des congés",
                // Une seule ligne par cabinet : le « titre » est le résumé des réglages
                // eux-mêmes. Afficher « Paramètres des congés » deux fois n'apprendrait rien.
                "texte_principal" => ["attribut_code" => "resumeReglages", "icone" => "action:settings"],
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Périodes de blocage : ", "attribut_code" => "nombrePeriodesBlocage"],
                ],
            ],
            "colonnes_numeriques" => [],
        ];
    }
}
