<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Avenant;

class AvenantFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Avenant::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Avenant $object */
        $isParentNew = ($object->getId() === null);
        $avenantId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvel Avenant",
            "titre_modification" => "Modification de l'Avenant #%id%",
            "endpoint_submit_url" => "/admin/avenant/api/submit",
            "endpoint_delete_url" => "/admin/avenant/api/delete",
            "endpoint_form_url" => "/admin/avenant/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildAvenantLayout($avenantId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildAvenantLayout(int $avenantId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["cotation"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["numero"]], ["champs" => ["referencePolice"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["startingAt"]], ["champs" => ["endingAt"]]]],
        ];

        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'avenant'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $avenantId, $isParentNew, $collections);
        return $layout;
    }
}