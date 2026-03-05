<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\TypeRevenu;

class TypeRevenuFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === TypeRevenu::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var TypeRevenu $object */
        $isParentNew = ($object->getId() === null);
        $typeRevenuId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Type de Revenu",
            "titre_modification" => "Modification du Type de Revenu #%id%",
            "endpoint_submit_url" => "/admin/typerevenu/api/submit",
            "endpoint_delete_url" => "/admin/typerevenu/api/delete",
            "endpoint_form_url" => "/admin/typerevenu/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildTypeRevenuLayout($typeRevenuId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildTypeRevenuLayout(int $typeRevenuId, bool $isParentNew): array
    {
        return [
            [
                'colonnes' => [
                    ['width' => 12, 'champs' => ['nom']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 12, 'champs' => ['modeCalcul']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 10, 'champs' => ['typeChargement']],
                    ['width' => 2, 'champs' => ['pourcentage']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 12, 'champs' => ['montantflat']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 12, 'champs' => ['appliquerPourcentageDuRisque']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 12, 'champs' => ['redevable']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 12, 'champs' => ['multipayments']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 12, 'champs' => ['shared']]
                ]
            ]
        ];
    }
}