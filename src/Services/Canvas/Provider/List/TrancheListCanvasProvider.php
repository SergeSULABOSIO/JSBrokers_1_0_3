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
                    ["attribut_prefixe" => "Échéance: ", "attribut_code" => "echeanceAt", "attribut_type" => "date"],
                    ["attribut_code" => "cotationNom"],
                    ["attribut_code" => "clientNom"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Prime Tranche",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "primeTranche",
                    "attribut_type" => "nombre",
                ],
                ["titre_colonne" => "Pourcentage", "attribut_unité" => "%", "attribut_code" => "pourcentageAffiche", "attribut_type" => "nombre"],
            ],
        ];
    }
}