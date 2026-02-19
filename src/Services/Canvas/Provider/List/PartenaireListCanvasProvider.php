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
                "texte_principal" => ["attribut_code" => "nom", "icone" => "partenaire"],
                "textes_secondaires" => [
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
            ],
        ];
    }
}
