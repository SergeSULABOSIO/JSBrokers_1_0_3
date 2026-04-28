<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Operation;
use App\Services\ServiceMonnaies;

class OperationListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Operation::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Opérations",
                "texte_principal" => ["attribut_code" => "referencePolice", "icone" => "operation"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Avenant: ", "attribut_code" => "numeroAvenant"],
                    ["attribut_prefixe" => "HT: ", "attribut_code" => "montantHT"],
                    ["attribut_prefixe" => "Taxe: ", "attribut_code" => "montantTaxe"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant HT",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantHT",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Montant Taxe",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantTaxe",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Montant TTC",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantTTC",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}