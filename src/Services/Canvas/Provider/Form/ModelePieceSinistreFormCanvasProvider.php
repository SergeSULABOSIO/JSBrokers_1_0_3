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
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Modèle de pièce sinistre",
                "description" => "Vous définissez un modèle de pièce attendue lors de l'instruction d'un sinistre : son intitulé, sa description et son caractère obligatoire. Ces modèles servent de liste de contrôle pour réunir les justificatifs requis.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "nom"         => "action:edit",
                "description" => "action:description",
                "obligatoire" => "action:check",
            ],
        ];
        $layout = $this->buildModelePieceSinistreLayout($modeleId, $isParentNew);

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