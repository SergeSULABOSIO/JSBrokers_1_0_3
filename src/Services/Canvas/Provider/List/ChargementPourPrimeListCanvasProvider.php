<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\ChargementPourPrime;
use App\Services\ServiceMonnaies;

class ChargementPourPrimeListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ChargementPourPrime::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Chargements",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "chargement"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Type: ", "attribut_code" => "type"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montant_final",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}