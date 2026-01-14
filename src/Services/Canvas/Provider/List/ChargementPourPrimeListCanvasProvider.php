<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\ChargementPourPrime;

class ChargementPourPrimeListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ChargementPourPrime::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Chargements sur Primes",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:cash-plus"],
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Cotation: ", "attribut_code" => "cotation"],
                ],
            ],
        ];
    }
}