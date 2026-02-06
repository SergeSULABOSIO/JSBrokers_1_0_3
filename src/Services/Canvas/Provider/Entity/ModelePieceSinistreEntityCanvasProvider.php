<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Entreprise;
use App\Entity\ModelePieceSinistre;
use App\Entity\PieceSinistre;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class ModelePieceSinistreEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ModelePieceSinistre::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Modèle de Pièce Sinistre",
                "icone" => "modele-piece",
                'background_image' => '/images/fitures/modelepiecesinistre.png',
                'description_template' => [
                    "Modèle de pièce: [[*nom]].",
                    " Obligatoire: [[statutObligation]].",
                    " « [[description]] »"
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "obligatoire", "intitule" => "Obligatoire", "type" => "Booleen"],
                ["code" => "entreprise", "intitule" => "Entreprise", "type" => "Relation", "targetEntity" => Entreprise::class, "displayField" => "nom"],
                ["code" => "pieceSinistres", "intitule" => "Pièces fournies", "type" => "Collection", "targetEntity" => PieceSinistre::class, "displayField" => "description"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "nombreUtilisations", "intitule" => "Nb. Utilisations", "type" => "Calcul", "format" => "Nombre", "fonction" => "countModelePieceSinistreUtilisations", "description" => "Nombre de fois que cette pièce a été fournie dans des dossiers sinistre."],
            ["code" => "statutObligation", "intitule" => "Statut Obligation", "type" => "Calcul", "format" => "Texte", "fonction" => "getModelePieceSinistreStatutObligationString", "description" => "Indique si la pièce est obligatoire ou facultative."],
        ];
    }
}