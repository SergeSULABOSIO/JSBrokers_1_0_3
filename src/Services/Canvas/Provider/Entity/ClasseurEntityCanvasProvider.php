<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Classeur;
use App\Entity\Entreprise;
use App\Entity\Document;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class ClasseurEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Classeur::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Classeur",
                "icone" => "classeur",
                'background_image' => '/images/fitures/classeur.png',
                'description_template' => [
                    "Classeur: [[*nom]].",
                    " <em>« [[description]] »</em>"
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "entreprise", "intitule" => "Entreprise", "type" => "Relation", "targetEntity" => Entreprise::class, "displayField" => "nom"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "nombreDocuments", "intitule" => "Nb. Documents", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre total de documents dans ce classeur."],
            ["code" => "ageClasseur", "intitule" => "Âge du classeur", "type" => "Calcul", "format" => "Texte", "description" => "Âge du classeur depuis sa création."],
            ["code" => "dateDernierAjout", "intitule" => "Dernier ajout", "type" => "Calcul", "format" => "Date", "description" => "Date à laquelle le dernier document a été ajouté."],
            ["code" => "apercuTypesFichiers", "intitule" => "Contenu", "type" => "Calcul", "format" => "ArrayAssoc", "description" => "Aperçu des types de fichiers contenus dans le classeur."],
            ["code" => "estVide", "intitule" => "Est vide", "type" => "Calcul", "format" => "Texte", "description" => "Indique si le classeur ne contient aucun document."],
        ];
    }
}