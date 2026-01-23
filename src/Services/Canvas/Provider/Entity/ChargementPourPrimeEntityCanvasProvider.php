<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class ChargementPourPrimeEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ChargementPourPrime::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Chargement sur Prime",
                "icone" => "mdi:cash-plus",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Chargement [[*nom]] d'un montant de [[montantFlatExceptionel]]",
                    " sur la cotation [[cotation]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "type", "intitule" => "Type de chargement", "type" => "Relation", "targetEntity" => Chargement::class, "displayField" => "nom"],
                ["code" => "cotation", "intitule" => "Cotation", "type" => "Relation", "targetEntity" => Cotation::class, "displayField" => "nom"],
                ["code" => "montantFlatExceptionel", "intitule" => "Montant", "type" => "Nombre", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "createdAt", "intitule" => "Créé le", "type" => "Date"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [];
    }
}