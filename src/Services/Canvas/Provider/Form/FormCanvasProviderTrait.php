<?php

namespace App\Services\Canvas\Provider\Form;

trait FormCanvasProviderTrait
{
    private function addCollectionWidgetsToLayout(array &$layout, object $parentEntity, bool $isParentNew, array $collectionsConfig): void
    {
        $parentId = $parentEntity->getId() ?? 0;
        foreach ($collectionsConfig as $config) {
            $extraOptions = [];
            if (isset($config['totalizableField']) && !$isParentNew) {
                $total = 0;
                $getter = 'get' . ucfirst($config['fieldName']);
                if (method_exists($parentEntity, $getter)) {
                    $collection = $parentEntity->{$getter}();
                    $valueGetter = 'get' . ucfirst($config['totalizableField']);
                    foreach ($collection as $item) {
                        if (method_exists($item, $valueGetter)) {
                            $total += $item->{$valueGetter}() ?? 0;
                        }
                    }
                }
                $extraOptions['totalizableField'] = $config['totalizableField'];
                $extraOptions['totalValue'] = $total;
            }

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
                        $isParentNew,
                        $extraOptions
                    )]],
                ]
            ];
        }
    }

    private function getCollectionWidgetConfig(string $fieldName, string $entityRouteName, int $parentId, string $formtitle, string $parentFieldName, ?array $defaultValueConfig = null, bool $isParentNew = false, array $extraOptions = []): array
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

        // Merge extra options (like totalizable info)
        if (!empty($extraOptions)) {
            $config['options'] = array_merge($config['options'], $extraOptions);
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