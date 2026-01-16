<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Cotation;

class CotationFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Cotation::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Cotation $object */
        $isParentNew = ($object->getId() === null);
        $cotationId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Cotation",
            "titre_modification" => "Modification de la Cotation #%id%",
            "endpoint_submit_url" => "/admin/cotation/api/submit",
            "endpoint_delete_url" => "/admin/cotation/api/delete",
            "endpoint_form_url" => "/admin/cotation/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildCotationLayout($cotationId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildCotationLayout(int $cotationId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["piste"]], ["champs" => ["assureur"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["duree"]]]],
        ];

        $collections = [
            ['fieldName' => 'avenants', 'entityRouteName' => 'avenant', 'formTitle' => 'Avenant', 'parentFieldName' => 'cotation'],
            ['fieldName' => 'taches', 'entityRouteName' => 'tache', 'formTitle' => 'Tâche', 'parentFieldName' => 'cotation'],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'cotation'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $cotationId, $isParentNew, $collections);
        return $layout;
    }
}
