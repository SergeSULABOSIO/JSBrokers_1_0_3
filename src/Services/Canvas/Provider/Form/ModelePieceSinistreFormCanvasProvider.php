<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\ModelePieceSinistre;
use App\Services\Canvas\Provider\Form\FormCanvasProviderInterface;
use App\Services\Canvas\Provider\Form\FormCanvasProviderTrait;

class ModelePieceSinistreFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ModelePieceSinistre::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var ModelePieceSinistre $object */
        $isParentNew = ($object->getId() === null);
        $modeleId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Modèle de Pièce Sinistre",
            "titre_modification" => "Modification du Modèle #%id%",
            "endpoint_submit_url" => "/admin/modelepiecesinistre/api/submit",
            "endpoint_delete_url" => "/admin/modelepiecesinistre/api/delete",
            "endpoint_form_url" => "/admin/modelepiecesinistre/api/get-form",
            "isCreationMode" => $isParentNew,
            // Colonne gauche du dialogue EN CRÉATION : ce que ce paramètre apporte
            // au cabinet. Le premier paragraphe sert aussi de « pourquoi » sur la
            // carte du guide de démarrage — un seul texte, deux surfaces.
            "description_creation" => [
                "Un type de pièce décrit un document à réclamer lors d'un sinistre : constat, facture, rapport d'expertise, procès-verbal.",
                "Sans cette liste, chaque gestionnaire réclame ce qui lui vient à l'esprit, et un dossier part chez l'assureur incomplet — ce qui retarde l'indemnisation de votre client.",
                "Ce sont vos modèles maison : adaptez-les aux branches que vous pratiquez réellement.",
            ],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Modèle de pièce sinistre",
                "description" => "Vous définissez un modèle de pièce attendue lors de l'instruction d'un sinistre : son intitulé, sa description et son caractère obligatoire. Ces modèles servent de liste de contrôle pour réunir les justificatifs requis.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "documents" => "document",
                "nom"         => "action:edit",
                "description" => "action:description",
                "obligatoire" => "action:check",
            ],
        ];
        $layout = $this->buildModelePieceSinistreLayout($modeleId, $isParentNew);

        // Pièces jointes de cette fiche.
        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'modelePieceSinistre'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildModelePieceSinistreLayout(int $modeleId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["obligatoire"]]]],
        ];

        return $layout;
    }
}