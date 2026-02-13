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
        $isCreateMode = ($object->getId() === null);

        $parametres = [
            'titre_creation' => "Création d'une nouvelle cotation",
            'titre_modification' => "Modification de la cotation n°%id%",
            'endpoint_form_url' => '/admin/cotation/api/get-form',
            'endpoint_submit_url' => '/admin/cotation/api/submit',
            'endpoint_delete_url' => '/admin/cotation/api/delete',
            'isCreationMode' => $isCreateMode
        ];

        $layout = $this->buildCotationLayout($object, $isCreateMode);

        return [
            'parametres' => $parametres,
            'form_layout' => $layout,
            'fields_map' => $this->buildFieldsMap($layout)
        ];
    }

    private function buildCotationLayout(Cotation $object, bool $isCreateMode): array
    {
        $cotationId = $object->getId() ?? 0;
        $layout = [
            [ // Ligne 1 : Nom (pleine largeur)
                'colonnes' => [
                    ['width' => 12, 'champs' => ['nom']]
                ]
            ],
            [ // Ligne 2 : Durée et Assureur (côte à côte)
                'colonnes' => [
                    ['width' => 4, 'champs' => ['duree']],
                    ['width' => 8, 'champs' => ['assureur']]
                ]
            ]
        ];

        $collections = [
            ['fieldName' => 'chargements', 'entityRouteName' => 'chargementpourprime', 'formTitle' => 'Chargement', 'parentFieldName' => 'cotation', 'totalizableField' => 'montantFlatExceptionel'],
            ['fieldName' => 'chargements', 'entityRouteName' => 'chargementpourprime', 'formTitle' => 'Chargement', 'parentFieldName' => 'cotation', 'totalizableField' => 'montant_final'],
            ['fieldName' => 'revenus', 'entityRouteName' => 'revenucourtier', 'formTitle' => 'Revenu', 'parentFieldName' => 'cotation'],
            ['fieldName' => 'tranches', 'entityRouteName' => 'tranche', 'formTitle' => 'Tranche', 'parentFieldName' => 'cotation'],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'cotation'],
            ['fieldName' => 'taches', 'entityRouteName' => 'tache', 'formTitle' => 'Tâche', 'parentFieldName' => 'cotation'],
            ['fieldName' => 'avenants', 'entityRouteName' => 'avenant', 'formTitle' => 'Avenant', 'parentFieldName' => 'cotation'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isCreateMode, $collections);

        return $layout;
    }
}