<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\DepenseCourtier;
use App\Services\ServiceMonnaies;

class DepenseCourtierListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === DepenseCourtier::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Dépenses",
                "texte_principal" => ["attribut_code" => "ligne_principale", "icone" => "hugeicons:dollar-send-02"],
                "textes_secondaires" => [
                    ["attribut_code" => "ligne_secondaire"],
                    ["attribut_prefixe" => "Statut : ", "attribut_code" => "statutLabel"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant TTC",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantTtc",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "TVA déductible",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "tvaDeductible",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}
