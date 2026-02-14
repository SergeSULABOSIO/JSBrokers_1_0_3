<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\RevenuPourCourtier;
use App\Services\ServiceMonnaies;

class RevenuPourCourtierListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RevenuPourCourtier::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Revenus Courtier",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "revenu"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Type: ", "attribut_code" => "typeRevenu"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantCalculeTTC",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}