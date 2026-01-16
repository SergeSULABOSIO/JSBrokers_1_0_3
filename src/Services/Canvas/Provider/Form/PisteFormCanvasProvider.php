<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Piste;

class PisteFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Piste::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Piste $object */
        $isParentNew = ($object->getId() === null);
        $pisteId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Piste",
            "titre_modification" => "Modification de la Piste #%id%",
            "endpoint_submit_url" => "/admin/piste/api/submit",
            "endpoint_delete_url" => "/admin/piste/api/delete",
            "endpoint_form_url" => "/admin/piste/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildPisteLayout($pisteId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildPisteLayout(int $pisteId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["client"]], ["champs" => ["risque"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["primePotentielle"]]]],
        ];

        $collections = [
            ['fieldName' => 'cotations', 'entityRouteName' => 'cotation', 'formTitle' => 'Cotation', 'parentFieldName' => 'piste'],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'piste'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $pisteId, $isParentNew, $collections);
        return $layout;
    }
}
