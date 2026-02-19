<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Risque;
use App\Services\ServiceMonnaies;

class RisqueListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Risque::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Risques",
                "texte_principal" => ["attribut_code" => "nomComplet", "icone" => "risque"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Code: ", "attribut_code" => "code"],
                    ["attribut_prefixe" => "Branche: ", "attribut_code" => "brancheString"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Prime Totale",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "prime_totale",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Comm. Totale",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "commission_totale",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Sinistre Payable",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "sinistre_payable",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Taux Sinistralité",
                    "attribut_unité" => "%",
                    "attribut_code" => "taux_sinistralite",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}