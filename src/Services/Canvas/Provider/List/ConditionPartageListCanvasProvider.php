<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\ConditionPartage;
use App\Services\ServiceMonnaies;

class ConditionPartageListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ConditionPartage::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Conditions de Partage",
                "texte_principal" => [
                    "attribut_code" => "nom", 
                    "icone" => "mdi:share-variant"
                    ],
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Taux: ", "attribut_code" => "taux", "attribut_type" => "pourcentage"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Assiette",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "commission_partageable",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Rétro-comm.",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "retro_commission_partenaire",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Rétro. Payée",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "retro_commission_partenaire_payee",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Réserve",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "reserve",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}