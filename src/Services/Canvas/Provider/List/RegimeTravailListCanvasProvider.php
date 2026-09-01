<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\RegimeTravail;

class RegimeTravailListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RegimeTravail::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Régimes de travail",
                "texte_principal" => ["attribut_code" => "periodeLibelle", "icone" => "streamline-flex:production-belt-time"],
                "textes_secondaires" => [
                    ["attribut_code" => "joursLibelle"],
                    ["attribut_prefixe" => "Taux : ", "attribut_code" => "tauxLibelle"],
                ],
            ],
            "colonnes_numeriques" => [],
        ];
    }
}
