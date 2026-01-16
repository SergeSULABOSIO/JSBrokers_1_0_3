<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\ConditionPartage;

class ConditionPartageFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ConditionPartage::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var ConditionPartage $object */
        $isParentNew = ($object->getId() === null);
        $conditionId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Condition de Partage",
            "titre_modification" => "Modification de la Condition #%id%",
            "endpoint_submit_url" => "/admin/conditionpartage/api/submit",
            "endpoint_delete_url" => "/admin/conditionpartage/api/delete",
            "endpoint_form_url" => "/admin/conditionpartage/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildConditionPartageLayout($conditionId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildConditionPartageLayout(int $conditionId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["partenaire"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["taux"]], ["champs" => ["seuil"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["formule"]], ["champs" => ["uniteMesure"]], ["champs" => ["critereRisque"]]]],
        ];
        $collections = [['fieldName' => 'produits', 'entityRouteName' => 'risque', 'formTitle' => 'Risque', 'parentFieldName' => 'conditionPartage']];
        $this->addCollectionWidgetsToLayout($layout, $conditionId, $isParentNew, $collections);
        return $layout;
    }
}
