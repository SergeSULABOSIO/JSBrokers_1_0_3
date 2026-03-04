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
                    [
                        'width' => 12,
                        'champs' => [
                            [
                                'field_code' => 'typeChargement',
                                'visibility_conditions' => [
                                    ['field' => 'modeCalcul', 'operator' => '==', 'value' => TypeRevenu::MODE_CALCUL_POURCENTAGE_CHARGEMENT]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            [
                'colonnes' => [
                    [
                        'width' => 6,
                        'champs' => [
                            [
                                'field_code' => 'pourcentage',
                                'visibility_conditions' => [
                                    ['field' => 'modeCalcul', 'operator' => '==', 'value' => TypeRevenu::MODE_CALCUL_POURCENTAGE_CHARGEMENT]
                                ]
                            ]
                        ]
                    ],
                    [
                        'width' => 6,
                        'champs' => [
                            [
                                'field_code' => 'montantflat',
                                'visibility_conditions' => [
                                    ['field' => 'modeCalcul', 'operator' => '==', 'value' => TypeRevenu::MODE_CALCUL_MONTANT_FLAT]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            [
                'colonnes' => [
                    [
                        'width' => 12,
                        'champs' => [
                            [
                                'field_code' => 'appliquerPourcentageDuRisque',
                                'visibility_conditions' => [
                                    ['field' => 'modeCalcul', 'operator' => '==', 'value' => TypeRevenu::MODE_CALCUL_POURCENTAGE_CHARGEMENT]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 6, 'champs' => ['redevable']],
                    ['width' => 6, 'champs' => ['multipayments']]
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