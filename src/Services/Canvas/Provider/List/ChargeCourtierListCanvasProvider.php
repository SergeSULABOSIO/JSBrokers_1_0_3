<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\ChargeCourtier;
use App\Services\ServiceMonnaies;

class ChargeCourtierListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ChargeCourtier::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Charges",
                "texte_principal" => ["attribut_code" => "libelle", "icone" => "tabler:receipt-tax"],
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Code : ", "attribut_code" => "code"],
                    ["attribut_prefixe" => "Compte : ", "attribut_code" => "compteOhadaFull"],
                    ["attribut_code" => "profilCharge"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Budget mensuel",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantBudgeteMensuelFloat",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}
