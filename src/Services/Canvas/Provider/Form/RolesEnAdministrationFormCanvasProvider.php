<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\RolesEnAdministration;

class RolesEnAdministrationFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnAdministration::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var RolesEnAdministration $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouveau Rôle en Administration",
            "titre_modification" => "Modification du Rôle #%id%",
            "endpoint_submit_url" => "/admin/rolesenadministration/api/submit",
            "endpoint_delete_url" => "/admin/rolesenadministration/api/delete",
            "endpoint_form_url" => "/admin/rolesenadministration/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildRolesEnAdministrationLayout();

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildRolesEnAdministrationLayout(): array
    {
        return [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["invite"]]]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessDocument"]], 
                ["champs" => ["accessClasseur"]], 
                ["champs" => ["accessInvite"]]
            ]],
        ];
    }
}