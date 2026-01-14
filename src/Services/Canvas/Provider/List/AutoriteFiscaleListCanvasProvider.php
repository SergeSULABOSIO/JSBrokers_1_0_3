<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\AutoriteFiscale;

class AutoriteFiscaleListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === AutoriteFiscale::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Autorités Fiscales",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:bank"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [["attribut_code" => "abreviation"]],
            ],
        ];
    }
}