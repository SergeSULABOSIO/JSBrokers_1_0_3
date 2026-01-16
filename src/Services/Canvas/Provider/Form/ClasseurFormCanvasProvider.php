<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Classeur;

class ClasseurFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Classeur::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Classeur $object */
        $isParentNew = ($object->getId() === null);
        $classeurId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Classeur",
            "titre_modification" => "Modification du Classeur #%id%",
            "endpoint_submit_url" => "/admin/classeur/api/submit",
            "endpoint_delete_url" => "/admin/classeur/api/delete",
            "endpoint_form_url" => "/admin/classeur/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildClasseurLayout($classeurId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildClasseurLayout(int $classeurId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
        ];
        $collections = [['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'classeur']];
        $this->addCollectionWidgetsToLayout($layout, $classeurId, $isParentNew, $collections);
        return $layout;
    }
}