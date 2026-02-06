<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Invite;

class InviteFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Invite::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Invite $object */
        $isParentNew = ($object->getId() === null);
        $inviteId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Invitation",
            "titre_modification" => "Modification de l'Invitation #%id%",
            "endpoint_submit_url" => "/admin/invite/api/submit",
            "endpoint_delete_url" => "/admin/invite/api/delete",
            "endpoint_form_url" => "/admin/invite/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildInviteLayout($inviteId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildInviteLayout(int $inviteId, bool $isParentNew): array
    {
        // Ligne 1: Informations de base de l'invité
        $layout = [
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["champs" => ["nom"]],
                    ["champs" => ["email"]]
                ]
            ],
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["champs" => ["assistants"]]
                ]
            ]
        ];

        // Les collections de rôles ne sont affichées qu'en mode édition,
        // car elles nécessitent un ID parent pour fonctionner.
        if (!$isParentNew) {
            $roleCollections = [
                'rolesEnFinance' => \App\Entity\RolesEnFinance::class,
                'rolesEnMarketing' => \App\Entity\RolesEnMarketing::class,
                'rolesEnProduction' => \App\Entity\RolesEnProduction::class,
                'rolesEnSinistre' => \App\Entity\RolesEnSinistre::class,
                'rolesEnAdministration' => \App\Entity\RolesEnAdministration::class,
            ];

            foreach ($roleCollections as $collectionName => $childEntityClass) {
                $layout[] = [
                    "couleur_fond" => "white",
                    "colonnes" => [
                        ["champs" => [[
                            "widget" => "collection",
                            "field_code" => $collectionName,
                            "options" => [
                                "parentEntityId" => $inviteId,
                                "collectionName" => $collectionName,
                            ]
                        ]]]
                    ]
                ];
            }
        }

        return $layout;
    }
}