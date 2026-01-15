<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Assureur;
use App\Entity\Bordereau;
use App\Entity\Document;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class BordereauEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Bordereau::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Bordereau",
                "icone" => "mdi:file-table-box-multiple",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Bordereau [[*nom]] de l'assureur [[assureur]]",
                    ", reçu le [[receivedAt]]",
                    " pour un montant total de [[montantTTC]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "assureur", "intitule" => "Assureur", "type" => "Relation", "targetEntity" => Assureur::class, "displayField" => "nom"],
                ["code" => "montantTTC", "intitule" => "Montant TTC", "type" => "Nombre", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "receivedAt", "intitule" => "Reçu le", "type" => "Date"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
            ], $this->canvasHelper->getGlobalIndicatorsCanvas("Bordereau"))
        ];
    }
}