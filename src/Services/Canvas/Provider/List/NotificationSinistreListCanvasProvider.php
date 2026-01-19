<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\NotificationSinistre;
use App\Services\ServiceMonnaies;

class NotificationSinistreListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === NotificationSinistre::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Sinistres",
                "texte_principal" => ["attribut_code" => "referenceSinistre", "icone" => "emojione-monotone:fire"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "assure"],
                    ["attribut_code" => "assureur"],
                    ["attribut_prefixe" => "Survenu le: ", "attribut_code" => "occuredAt", "attribut_type" => "date"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Dommage (av. éval.)",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "dommage",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Dommage (ap. éval.)",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "evaluationChiffree",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Compensation Due",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "compensation",
                    "attribut_type" => "nombre",
                ],
            ],
        ];
    }
}