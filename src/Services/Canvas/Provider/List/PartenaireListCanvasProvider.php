<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Partenaire;
use App\Services\ServiceMonnaies;

class PartenaireListCanvasProvider implements ListCanvasProviderInterface
{

    public function __construct(private ServiceMonnaies $serviceMonnaies) {}

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Partenaire::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Partenaires",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:account-tie"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "telephone"],
                    ["attribut_code" => "email"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Part (%)",
                    "attribut_unité" => "%",
                    "attribut_code" => "partPourcentage",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Assiette",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantPur",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Rétro-comm.",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "retroCommission",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Rétro. Payée",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "retroCommissionReversee",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}
