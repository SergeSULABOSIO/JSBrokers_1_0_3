<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Entreprise;

class EntrepriseListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Entreprise::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Entreprises",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:office-building"],
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Licence: ", "attribut_code" => "licence"],
                ],
            ],
        ];
    }
}