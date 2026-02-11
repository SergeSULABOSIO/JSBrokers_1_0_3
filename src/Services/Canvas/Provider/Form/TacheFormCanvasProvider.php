<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Tache;

class TacheFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tache::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Tache $object */
        $isParentNew = ($object->getId() === null);
        $tacheId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle tâche",
            "titre_modification" => "Modification de la tâche #%id%",
            "endpoint_submit_url" => "/admin/tache/api/submit",
            "endpoint_delete_url" => "/admin/tache/api/delete",
            "endpoint_form_url" => "/admin/tache/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildTacheLayout($tacheId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildTacheLayout(int $tacheId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["toBeEndedAt"]], ["champs" => ["executor"]], ["champs" => ["closed"]]]],
        ];

        $collections = [
            ['fieldName' => 'feedbacks', 'entityRouteName' => 'feedback', 'formTitle' => 'Feedback', 'parentFieldName' => 'tache'],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'tache'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $tacheId, $isParentNew, $collections);
        return $layout;
    }
}
