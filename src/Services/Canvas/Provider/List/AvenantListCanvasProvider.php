<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Avenant;
use App\Services\ServiceMonnaies;

class AvenantListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Avenant::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Avenants",
                "texte_principal" => [
                    "attribut_code" => "titrePrincipal",
                    "icone" => "mdi:file-document-edit",
                ],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Avt n°", "attribut_code" => "numero"],
                    ["attribut_code" => "risqueCode"],
                    ["attribut_code" => "periodeCouverture"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Prime Totale",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "primeTotale",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Comm. Totale",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantTTC",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Rétro-comm.",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "retroCommission",
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