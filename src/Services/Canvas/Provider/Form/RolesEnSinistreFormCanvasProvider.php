<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\RolesEnSinistre;

class RolesEnSinistreFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnSinistre::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var RolesEnSinistre $object */
        $isParentNew = ($object->getId() === null);
        $roleId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Rôle en Sinistre",
            "titre_modification" => "Modification du Rôle #%id%",
            "endpoint_submit_url" => "/admin/rolesensinistre/api/submit",
            "endpoint_delete_url" => "/admin/rolesensinistre/api/delete",
            "endpoint_form_url" => "/admin/rolesensinistre/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildRolesEnSinistreLayout($roleId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildRolesEnSinistreLayout(int $roleId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["invite"]]]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessTypePiece"]], 
                ["champs" => ["accessNotification"]], 
                ["champs" => ["accessReglement"]]
            ]],
        ];
        return $layout;
    }
}