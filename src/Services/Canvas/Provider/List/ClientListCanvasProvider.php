<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Client;
use App\Services\ServiceMonnaies;

class ClientListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Client::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Clients",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:account-group"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    // Portefeuille d'affectation en tête (avec icône) : information de
                    // rattachement la plus utile pour scanner la liste des clients.
                    ["attribut_code" => "portefeuilleNom", "icone" => "lucide:folder"],
                    ["attribut_code" => "groupeNom", "icone" => "lucide:users"],
                    ["attribut_code" => "email", "icone" => "lucide:mail"],
                    ["attribut_code" => "telephone", "icone" => "lucide:phone"],
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
