<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Groupe;
use App\Services\ServiceMonnaies;

class GroupeListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Groupe::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Groupes de clients",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:account-multiple"],
                "textes_secondaires" => [
                    ["attribut_code" => "nombreClients", "attribut_label" => "Clients : "],
                    ["attribut_code" => "description", "attribut_taille_max" => 50],
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