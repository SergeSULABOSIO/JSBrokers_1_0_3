<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Paiement;
use App\Services\ServiceMonnaies;

class PaiementListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Paiement::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Paiements",
                "texte_principal" => ["attribut_code" => "ligne_principale", "icone" => "paiement"],
                "textes_secondaires" => [
                    ["attribut_code" => "ligne_secondaire"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantPaiement",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}