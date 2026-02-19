<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Cotation;
use App\Services\Canvas\Provider\List\ListCanvasProviderInterface;
use App\Services\ServiceMonnaies;

class CotationListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Cotation::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Cotations",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "cotation"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "assureur"],
                    ["attribut_prefixe" => "Piste: ", "attribut_code" => "piste"],
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