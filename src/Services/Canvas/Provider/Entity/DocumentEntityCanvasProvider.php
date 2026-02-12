<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Feedback;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\PieceSinistre;
use App\Entity\Piste;
use App\Entity\Tache;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.entity_canvas_provider')]
class DocumentEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Document::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Document",
                "icone" => "document",
                'background_image' => '/images/fitures/document.png',
                'description_template' => ["Document: [[*nom]]. Parent: [[parent_string]]. Fichier: [[nomFichierStocke]]."]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "nomFichierStocke", "intitule" => "Nom Fichier", "type" => "Texte"],
                ["code" => "createdAt", "intitule" => "Créé le", "type" => "Date"],
                ["code" => "updatedAt", "intitule" => "Modifié le", "type" => "Date"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "parent_string", "intitule" => "Contexte", "type" => "Calcul", "format" => "Texte", "fonction" => "Document_getParentAsString", "description" => "Entité parente à laquelle le document est rattaché."],
            ["code" => "classeur_string", "intitule" => "Classement", "type" => "Calcul", "format" => "Texte", "fonction" => "Document_getClasseurAsString", "description" => "Classeur dans lequel le document est archivé."],
            ["code" => "ageDocument", "intitule" => "Âge du document", "type" => "Calcul", "format" => "Texte", "fonction" => "calculateDocumentAge", "description" => "Âge du document depuis sa création."],
            ["code" => "typeFichier", "intitule" => "Type de fichier", "type" => "Calcul", "format" => "Texte", "fonction" => "getDocumentTypeFichier", "description" => "Extension du fichier."],
        ];
    }
}