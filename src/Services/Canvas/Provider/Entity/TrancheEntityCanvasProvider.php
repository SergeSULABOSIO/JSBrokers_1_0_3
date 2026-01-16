<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Article;
use App\Entity\Cotation;
use App\Entity\Tranche;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class TrancheEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tranche::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Tranche de Paiement",
                "icone" => "mdi:chart-pie-outline",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Tranche [[*nom]] pour la cotation [[cotation]].",
                    " Montant: [[montantFlat]] ou [[pourcentage]]%.",
                    " Payable le [[payableAt]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "cotation", "intitule" => "Cotation", "type" => "Relation", "targetEntity" => Cotation::class, "displayField" => "nom"],
                ["code" => "montantFlat", "intitule" => "Montant Fixe", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "pourcentage", "intitule" => "Pourcentage", "type" => "Nombre", "unite" => "%"],
                ["code" => "payableAt", "intitule" => "Payable le", "type" => "Date"],
                ["code" => "echeanceAt", "intitule" => "Échéance le", "type" => "Date"],
                ["code" => "articles", "intitule" => "Articles de note", "type" => "Collection", "targetEntity" => Article::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators(), $this->canvasHelper->getGlobalIndicatorsCanvas("Tranche"))
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "ageTranche", "intitule" => "Âge", "type" => "Texte", "format" => "Texte", "description" => "Nombre de jours depuis la création de la tranche."],
            ["code" => "joursRestantsAvantEcheance", "intitule" => "Jours Restants", "type" => "Texte", "format" => "Texte", "description" => "Nombre de jours restants avant la date d'échéance."],
        ];
    }
}