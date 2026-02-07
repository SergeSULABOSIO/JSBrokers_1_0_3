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
            $collectionsConfig = [
                [
                    'fieldName' => 'rolesEnFinance', 'entityRouteName' => 'rolesenfinance',
                    'formTitle' => 'Rôle en Finance', 'parentFieldName' => 'invite'
                ],
                [
                    'fieldName' => 'rolesEnMarketing', 'entityRouteName' => 'rolesenmarketing',
                    'formTitle' => 'Rôle en Marketing', 'parentFieldName' => 'invite'
                ],
                [
                    'fieldName' => 'rolesEnProduction', 'entityRouteName' => 'rolesenproduction',
                    'formTitle' => 'Rôle en Production', 'parentFieldName' => 'invite'
                ],
                [
                    'fieldName' => 'rolesEnSinistre', 'entityRouteName' => 'rolesensinistre',
                    'formTitle' => 'Rôle en Sinistre', 'parentFieldName' => 'invite'
                ],
                [
                    'fieldName' => 'rolesEnAdministration', 'entityRouteName' => 'rolesenadministration',
                    'formTitle' => 'Rôle en Administration', 'parentFieldName' => 'invite'
                ],
            ];

            $this->addCollectionWidgetsToLayout($layout, $inviteId, $isParentNew, $collectionsConfig);
        }

        return $layout;
    }
}