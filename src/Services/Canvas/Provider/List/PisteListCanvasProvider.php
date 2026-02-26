<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Piste;
use App\Services\ServiceMonnaies;

class PisteListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

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
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "statutTransformation"],
                    ["attribut_code" => "client"],
                    ["attribut_code" => "risqueCode"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Prime Potentielle",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "primePotentielle",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Comm. Potentielle",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "commissionPotentielle",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Prime Souscrite",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "primeTotale",
                    "attribut_type" => "calcul", // Changé pour refléter que c'est un calcul
                ],
                [
                    "titre_colonne" => "Comm. Souscrite",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantTTC",
                    "attribut_type" => "calcul", // Changé pour refléter que c'est un calcul
                ],
            ],
        ];
    }
}