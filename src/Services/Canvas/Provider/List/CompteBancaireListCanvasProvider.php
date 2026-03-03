<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\CompteBancaire;
use App\Services\ServiceMonnaies;

class CompteBancaireListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === CompteBancaire::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Comptes Bancaires",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:bank"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "N° ", "attribut_code" => "numero"],
                    ["attribut_code" => "banque"],
                    ["attribut_prefixe" => "Swift: ", "attribut_code" => "codeSwift"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Entrées",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "totalEntrees",
                    "attribut_type" => "calcul",
                ],
                [
                    "titre_colonne" => "Sorties",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "totalSorties",
                    "attribut_type" => "calcul",
                ],
                [
                    "titre_colonne" => "Solde",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "soldeActuel",
                    "attribut_type" => "calcul",
                ],
            ],
        ];
    }
}