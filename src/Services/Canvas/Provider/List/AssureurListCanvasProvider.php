<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Assureur;
use App\Services\ServiceMonnaies;

class AssureurListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Assureur::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Assureurs",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:shield-check"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "email"],
                    ["attribut_code" => "telephone"],
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
                    "titre_colonne" => "Sinistre Payé",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "sinistre_paye",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}