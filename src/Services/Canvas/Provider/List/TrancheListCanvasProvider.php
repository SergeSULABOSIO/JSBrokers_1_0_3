<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Tranche;
use App\Services\ServiceMonnaies;

class TrancheListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tranche::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Tranches",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:chart-pie"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Cotation: ", "attribut_code" => "cotation"],
                    ["attribut_prefixe" => "Payable le: ", "attribut_code" => "payableAt", "attribut_type" => "date"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantFlat",
                    "attribut_type" => "nombre",
                ],
                ["titre_colonne" => "Pourcentage", "attribut_unité" => "%", "attribut_code" => "pourcentage", "attribut_type" => "nombre"],
            ],
        ];
    }
}