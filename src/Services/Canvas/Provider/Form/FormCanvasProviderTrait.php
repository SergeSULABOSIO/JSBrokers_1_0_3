<?php

namespace App\Services\Canvas\Provider\Form;

trait FormCanvasProviderTrait
{
    private function addCollectionWidgetsToLayout(array &$layout, int $parentId, bool $isParentNew, array $collectionsConfig): void
    {
        foreach ($collectionsConfig as $config) {
            $layout[] = [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["champs" => [$this->getCollectionWidgetConfig(
                        $config['fieldName'],
                        $config['entityRouteName'],
                        $parentId,
                        $config['formTitle'],
                        $config['parentFieldName'],
                        $config['defaultValueConfig'] ?? null,
                        $isParentNew
                    )]],
                ]
            ];
        }
    }

    private function getCollectionWidgetConfig(string $fieldName, string $entityRouteName, int $parentId, string $formtitle, string $parentFieldName, ?array $defaultValueConfig = null, bool $isParentNew = false): array
    {
        $config = [
            "field_code" => $fieldName,
            "widget" => "collection",
            "options" => [
                "listUrl"       => "/admin/" . strtolower($parentFieldName) . "/api/" . $parentId . "/" . $fieldName,
                "itemFormUrl"   => "/admin/" . $entityRouteName . "/api/get-form",
                "itemSubmitUrl" => "/admin/" . $entityRouteName . "/api/submit",
                "itemDeleteUrl" => "/admin/" . $entityRouteName . "/api/delete",
                "itemTitleCreate" => "Ajouter : " . $formtitle,
                "itemTitleEdit" => "Modifier : " . $formtitle . " #%id%",
                "parentEntityId" => $parentId,
                "parentFieldName" => $parentFieldName,
                "disabled" => $isParentNew,
                "url" => "/admin/" . strtolower($parentFieldName) . "/api/" . $parentId . "/" . $fieldName,
            ]
        ];

        if ($defaultValueConfig) {
            $config['options']['defaultValueConfig'] = json_encode($defaultValueConfig);
        }

        return $config;
    }

    private function buildFieldsMap(array $formLayout): array
    {
        $fieldsMap = [];
        if (empty($formLayout)) {
            return $fieldsMap;
        }

        foreach ($formLayout as $row) {
            if (!isset($row['colonnes']) || !is_array($row['colonnes'])) continue;

            foreach ($row['colonnes'] as $col) {
                $fields = $col['champs'] ?? (is_array($col) ? [$col] : []);

                foreach ($fields as $field) {
                    if (is_array($field) && isset($field['field_code'])) {
                        $fieldsMap[$field['field_code']] = $field;
                    }
                }
            }
        }
        return $fieldsMap;
    }
}