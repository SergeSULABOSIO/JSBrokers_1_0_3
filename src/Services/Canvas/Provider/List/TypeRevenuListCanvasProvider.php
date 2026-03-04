<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\TypeRevenu;
use App\Services\ServiceMonnaies;

class TypeRevenuListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === TypeRevenu::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Types de Revenu",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "type-revenu"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Mode: ", "attribut_code" => "descriptionModeCalcul"],
                    ["attribut_prefixe" => "Redevable: ", "attribut_code" => "redevableString"],
                    ["attribut_prefixe" => "Partagé: ", "attribut_code" => "sharedString"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant Fixe",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantflat",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Pourcentage",
                    "attribut_unité" => "%",
                    "attribut_code" => "pourcentage",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}