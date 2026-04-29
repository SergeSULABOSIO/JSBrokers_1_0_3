<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Bordereau;
use App\Services\ServiceMonnaies;

class BordereauListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Bordereau::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Bordereaux",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "bordereau"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "reference"],
                    ["attribut_code" => "typeString"],
                    ["attribut_prefixe" => "Assureur: ", "attribut_code" => "assureurNom"],
                    ["attribut_prefixe" => "Reçu le: ", "attribut_code" => "receivedAt", "attribut_type" => "date"],
                    ["attribut_prefixe" => "Statut: ", "attribut_code" => "statutString"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Total dû",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantCommissionTTC",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Encaissé",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantEncaisse",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Solde",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "solde",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}