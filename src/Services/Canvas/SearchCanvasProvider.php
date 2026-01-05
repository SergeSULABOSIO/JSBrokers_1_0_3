<?php

namespace App\Services\Canvas;

class SearchCanvasProvider
{
    public function __construct(private EntityCanvasProvider $entityCanvasProvider)
    {
    }

    /**
     * Construit le "canevas de recherche" pour une entité donnée.
     * Ce canevas définit les critères disponibles pour la recherche simple et avancée,
     * en s'inspirant de la structure utilisée par le contrôleur Stimulus `search-bar`.
     *
     * @param string $entityClassName Le FQCN (Fully Qualified Class Name) de l'entité.
     * @return array Un tableau de définitions de critères.
     */
    public function getCanvas(string $entityClassName): array
    {
        $searchCriteria = [];
        $entityCanvas = $this->entityCanvasProvider->getCanvas($entityClassName);

        // Si aucun canevas n'est défini pour cette entité, on ne peut rien faire.
        if (empty($entityCanvas) || !isset($entityCanvas['liste'])) {
            return [];
        }

        foreach ($entityCanvas['liste'] as $field) {
            // On ignore les collections car elles ne sont pas des champs de recherche directs.
            if ($field['type'] === 'Collection') {
                continue;
            }

            // NOUVEAU : On ignore le champ 'id' qui n'est pas un critère de recherche pertinent.
            if ($field['code'] === 'id') {
                continue;
            }

            $criterion = [
                'Nom' => $field['code'],
                'Display' => $field['intitule'],
                'isDefault' => false, // Par défaut, aucun n'est le critère simple.
            ];

            // Mappage des types PHP vers les types attendus par le JavaScript
            switch ($field['type']) {
                case 'Texte':
                    $criterion['Type'] = 'Text';
                    $criterion['Valeur'] = '';
                    break;
                case 'Relation': // Les relations sont souvent recherchées via un champ texte.
                    $criterion['Type'] = 'Text'; // Pour le frontend, c'est un champ texte.
                    $criterion['Valeur'] = '';
                    $criterion['targetField'] = $field['displayField'] ?? 'nom'; // On spécifie sur quel champ de la relation chercher.
                    break;

                case 'Nombre':
                case 'Entier':
                    $criterion['Type'] = 'Number';
                    $criterion['Valeur'] = 0;
                    break;

                case 'Date':
                    // Un champ de date unique est transformé en une plage de dates pour la recherche.
                    $criterion['Type'] = 'DateTimeRange';
                    $criterion['Valeur'] = ['from' => '', 'to' => ''];
                    break;

                case 'Booleen':
                    $criterion['Type'] = 'Options'; // Un booléen peut être représenté par des options "Oui/Non".
                    $criterion['Valeur'] = [
                        '1' => 'Oui',
                        '0' => 'Non',
                    ];
                    break;

                default:
                    continue 2; // On saute ce champ si son type n'est pas géré.
            }
            $searchCriteria[] = $criterion;
        }
        return $searchCriteria;
    }
}