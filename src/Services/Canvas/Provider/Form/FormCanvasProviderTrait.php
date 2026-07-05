<?php

namespace App\Services\Canvas\Provider\Form;

use App\Services\CanvasBuilder;

trait FormCanvasProviderTrait
{
    private function addCollectionWidgetsToLayout(array &$layout, object $parentEntity, bool $isParentNew, array $collectionsConfig, ?int $idEntreprise = null, ?int $idInvite = null, ?CanvasBuilder $canvasBuilder = null): void
    {
        $parentId = $parentEntity->getId() ?? 0;
        foreach ($collectionsConfig as $config) {
            $extraOptions = [];
            
            // Capture des options de rendu pour la ligne secondaire enrichie
            if (isset($config['secondaryField'])) $extraOptions['secondaryField'] = $config['secondaryField'];
            if (isset($config['secondaryLabel'])) $extraOptions['secondaryLabel'] = $config['secondaryLabel'];
            if (isset($config['watchIds'])) $extraOptions['watchIds'] = $config['watchIds'];
            // Permet de remplacer l'URL de suppression par défaut (child::delete) par une
            // action non destructive — ex. « détacher » un client d'un portefeuille sans
            // supprimer l'entité partagée. %parentId% est substitué par l'ID du parent.
            if (isset($config['itemDeleteUrl'])) {
                $extraOptions['itemDeleteUrl'] = str_replace('%parentId%', (string) $parentId, $config['itemDeleteUrl']);
            }
            // Mode « sélection de ressources existantes » : le bouton Ajouter ouvre une
            // boîte de choix (ex. clients d'un portefeuille) au lieu d'un formulaire.
            if (isset($config['pickerUrl'])) {
                $extraOptions['pickerUrl'] = str_replace('%parentId%', (string) $parentId, $config['pickerUrl']);
            }
            // Personnalisation des actions de ligne (bouton d'édition masqué, libellé/icône
            // de l'action de suppression — ex. « Retirer » pour un détachement).
            if (isset($config['hideEditAction']))    $extraOptions['hideEditAction'] = $config['hideEditAction'];
            if (isset($config['deleteActionLabel']))  $extraOptions['deleteActionLabel'] = $config['deleteActionLabel'];
            if (isset($config['deleteActionIcon']))   $extraOptions['deleteActionIcon'] = $config['deleteActionIcon'];

            if (isset($config['totalizableField']) && !$isParentNew) {
                $total = 0;
                $getter = 'get' . ucfirst($config['fieldName']);
                if (method_exists($parentEntity, $getter)) {
                    $collection = $parentEntity->{$getter}();

                    $fieldName = $config['totalizableField'];
                    // Convertit snake_case (ex: montant_final) en PascalCase (MontantFinal) pour le getter.
                    $camelCaseField = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName))));
                    $valueGetter = 'get' . ucfirst($camelCaseField);

                    foreach ($collection as $item) {
                        // ÉTAPE CRUCIALE : Charger les valeurs calculées pour l'élément avant de les utiliser.
                        if (property_exists($this, 'canvasBuilder') && $this->canvasBuilder instanceof CanvasBuilder) {
                            $this->canvasBuilder->loadAllCalculatedValues($item);
                        }

                        $value = 0;
                        // Essayer le getter d'abord (ex: getMontantFinal())
                        if (method_exists($item, $valueGetter)) {
                            $value = $item->{$valueGetter}();
                        // Sinon, vérifier la propriété publique (ex: montant_final)
                        } elseif (property_exists($item, $fieldName) && isset($item->{$fieldName})) {
                            $value = $item->{$fieldName};
                        }
                        $total += $value ?? 0;
                    }
                }
                $extraOptions['totalizableField'] = $config['totalizableField'];
                $extraOptions['totalValue'] = $total;
            }

            // On détermine si le widget doit être désactivé.
            // Par défaut, on désactive en mode création ($isParentNew),
            // SAUF si le provider a spécifié une règle personnalisée dans $config['disabled'].
            $isDisabled = $config['disabled'] ?? $isParentNew;

            // En création, une collection désactivée est inutilisable (pas d'ID parent).
            // Plutôt que de l'afficher grisée, on la masque (d-none) : la ligne reste
            // rendue — ce qui empêche form_end(render_rest) de produire un accordéon
            // brut en bas du formulaire — mais elle n'est pas visible.
            $isHidden = $isParentNew && $isDisabled;

            $layout[] = [
                "couleur_fond" => "white",
                "hidden" => $isHidden,
                "colonnes" => [
                    ["champs" => [$this->getCollectionWidgetConfig(
                        $config['fieldName'],
                        $config['entityRouteName'],
                        $parentId,
                        $config['formTitle'],
                        $config['parentFieldName'],
                        $config['defaultValueConfig'] ?? null,
                        $isDisabled,
                        $extraOptions,
                        $idEntreprise,
                        $idInvite
                    )]], // Correction: Ajout des IDs
                ]
            ];
        }
    }

    private function getCollectionWidgetConfig(string $fieldName, string $entityRouteName, int $parentId, string $formtitle, string $parentFieldName, ?array $defaultValueConfig = null, bool $isDisabled = false, array $extraOptions = [], ?int $idEntreprise = null, ?int $idInvite = null): array
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
                "disabled" => $isDisabled,
                "url" => "/admin/" . strtolower($parentFieldName) . "/api/" . $parentId . "/" . $fieldName,
                "idEntreprise" => $idEntreprise,
                "idInvite" => $idInvite,
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