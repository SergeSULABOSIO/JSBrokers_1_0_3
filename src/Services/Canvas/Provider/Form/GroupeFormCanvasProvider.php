<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Groupe;

class GroupeFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Groupe::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Groupe $object */
        $isParentNew = ($object->getId() === null);
        $groupeId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Groupe",
            "titre_modification" => "Modification du Groupe #%id%",
            "endpoint_submit_url" => "/admin/groupe/api/submit",
            "endpoint_delete_url" => "/admin/groupe/api/delete",
            "endpoint_form_url" => "/admin/groupe/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildGroupeLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildGroupeLayout(Groupe $object, bool $isParentNew): array
    {
        $groupeId = $object->getId() ?? 0;
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
        ];
        $collections = [['fieldName' => 'clients', 'entityRouteName' => 'client', 'formTitle' => 'Client', 'parentFieldName' => 'groupe']];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}
