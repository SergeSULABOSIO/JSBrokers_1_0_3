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
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:file-table-box-multiple"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "assureur"],
                    ["attribut_prefixe" => "Reçu le: ", "attribut_code" => "receivedAt", "attribut_type" => "date"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant TTC",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantTTC",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}