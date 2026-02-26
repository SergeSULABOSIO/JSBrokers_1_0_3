<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Piste;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class PisteFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

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
            "isCreationMode" => $isParentNew,
        ];
        $layout = $this->buildPisteLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout),
            "idEntreprise" => $idEntreprise,
            "idInvite" => $object->getInvite()?->getId(),
        ];
    }

    private function buildPisteLayout(Piste $object, bool $isParentNew): array
    {
        $pisteId = $object->getId() ?? 0;
        $layout = [
            [
                'colonnes' => [
                    ['width' => 12, 'champs' => ['nom']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 12, 'champs' => ['descriptionDuRisque']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 12, 'champs' => ['client']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 12, 'champs' => ['risque']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 6, 'champs' => ['typeAvenant']],
                    ['width' => 6, 'champs' => ['renewalCondition']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 3, 'champs' => ['exercice']],
                    ['width' => 5, 'champs' => ['primePotentielle']],
                    ['width' => 4, 'champs' => ['commissionPotentielle']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 12, 'champs' => ['partenaires']]
                ]
            ],
        ];

        $collections = [
            ['fieldName' => 'conditionsPartageExceptionnelles', 'entityRouteName' => 'conditionpartage', 'formTitle' => 'Conditions de partage', 'parentFieldName' => 'piste'],
            ['fieldName' => 'cotations', 'entityRouteName' => 'cotation', 'formTitle' => 'Cotation', 'parentFieldName' => 'piste'],
            ['fieldName' => 'taches', 'entityRouteName' => 'tache', 'formTitle' => 'Tâches', 'parentFieldName' => 'piste'],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'piste'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}
