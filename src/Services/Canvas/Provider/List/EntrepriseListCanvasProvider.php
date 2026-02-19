<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Entreprise;
use App\Services\ServiceMonnaies;

class EntrepriseListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Entreprise::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Entreprises",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:office-building"],
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Licence: ", "attribut_code" => "licence"],
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
                    "titre_colonne" => "Réserve",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "reserve",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Taxe Courtier",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "taxe_courtier",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}