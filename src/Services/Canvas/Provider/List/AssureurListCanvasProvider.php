<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Assureur;

class AssureurListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Assureur::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Assureurs",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:shield-check"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "email"],
                    ["attribut_code" => "telephone"],
                ],
            ],
        ];
    }
}