<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Portefeuille;
use App\Services\ServiceMonnaies;

class PortefeuilleListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Portefeuille::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Portefeuilles",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:briefcase-account"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "gestionnaire", "attribut_label" => "Gestionnaire : "],
                    ["attribut_code" => "nombreClients", "attribut_label" => "Clients : "],
                ],
            ],
            // Agrégats monétaires des clients du portefeuille, hydratés par
            // PortefeuilleIndicatorStrategy (mêmes codes que la fiche Portefeuille).
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Primes",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "primeTotale",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Sinistres",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "indemnisationVersee",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Commissions",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantTTC",
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
