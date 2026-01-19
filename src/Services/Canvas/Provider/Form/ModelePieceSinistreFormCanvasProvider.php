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
            "isCreationMode" => $isParentNew
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