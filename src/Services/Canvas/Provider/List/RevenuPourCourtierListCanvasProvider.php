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
                "texte_principal" => ["attribut_code" => "nomCompletAvecStatut", "icone" => "revenu"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Client: ", "attribut_code" => "clientNom"],
                    ["attribut_prefixe" => "Type: ", "attribut_code" => "typeRevenuNom"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant TTC",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantCalculeTTC",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Solde Dû",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "solde_restant_du",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}