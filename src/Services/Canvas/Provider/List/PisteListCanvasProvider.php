<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Piste;

class PisteListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Piste::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Pistes",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:road-variant"],
                "textes_secondaires" => [["attribut_code" => "client"]],
            ],
        ];
    }
}