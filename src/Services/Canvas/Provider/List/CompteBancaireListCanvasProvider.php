<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\CompteBancaire;

class CompteBancaireListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === CompteBancaire::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Comptes Bancaires",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:bank"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "intitule"],
                    ["attribut_prefixe" => "N° ", "attribut_code" => "numero"],
                ],
            ],
        ];
    }
}