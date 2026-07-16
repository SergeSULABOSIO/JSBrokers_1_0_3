<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\PaiementPrime;
use App\Services\ServiceMonnaies;

class PaiementPrimeListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === PaiementPrime::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Paiements de prime signalés",
                "texte_principal" => ["attribut_code" => "reference", "icone" => "paiement"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Payée le : ", "attribut_code" => "paidAt", "attribut_type" => "date"],
                    ["attribut_code" => "description", "attribut_type" => "text", "attribut_taille_max" => 90],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant réglé",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montant",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}
