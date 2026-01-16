<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Article;
use App\Entity\Cotation;
use App\Entity\RevenuPourCourtier;
use App\Entity\TypeRevenu;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class RevenuPourCourtierEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RevenuPourCourtier::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Revenu pour Courtier",
                "icone" => "mdi:cash-register",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Revenu [[*nom]] sur la cotation [[cotation]].",
                    " Type: [[typeRevenu]].",
                    " Montant HT: [[montantCalculeHT]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "typeRevenu", "intitule" => "Type de Revenu", "type" => "Relation", "targetEntity" => TypeRevenu::class, "displayField" => "nom"],
                ["code" => "cotation", "intitule" => "Cotation", "type" => "Relation", "targetEntity" => Cotation::class, "displayField" => "nom"],
                ["code" => "montantFlatExceptionel", "intitule" => "Montant Fixe (Except.)", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "tauxExceptionel", "intitule" => "Taux (Except.)", "type" => "Nombre", "unite" => "%"],
                ["code" => "createdAt", "intitule" => "Créé le", "type" => "Date"],
                ["code" => "articles", "intitule" => "Articles de note", "type" => "Collection", "targetEntity" => Article::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators(), $this->canvasHelper->getGlobalIndicatorsCanvas("RevenuPourCourtier"))
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "montantCalculeHT", "intitule" => "Montant HT", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant du revenu calculé avant taxes."],
            ["code" => "montantCalculeTTC", "intitule" => "Montant TTC", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant du revenu calculé toutes taxes comprises."],
            ["code" => "descriptionCalcul", "intitule" => "Détail du Calcul", "type" => "Texte", "format" => "Texte", "description" => "Description de la méthode de calcul appliquée pour ce revenu."],
        ];
    }
}