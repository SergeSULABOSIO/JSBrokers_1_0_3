<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Fournisseur;

class FournisseurListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Fournisseur::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Fournisseurs",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "tabler:building-store"],
                "textes_secondaires" => [
                    ["attribut_code" => "coordonnees"],
                    ["attribut_prefixe" => "Statut : ", "attribut_code" => "actifLabel"],
                ],
            ],
            "colonnes_numeriques" => [],
        ];
    }
}
