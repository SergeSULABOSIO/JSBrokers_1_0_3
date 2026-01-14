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
                "texte_principal" => ["attribut_code" => "reference", "icone" => "mdi:cash-multiple"],
                "textes_secondaires" => [
                    ["attribut_code" => "description", "attribut_taille_max" => 50],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montant",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}