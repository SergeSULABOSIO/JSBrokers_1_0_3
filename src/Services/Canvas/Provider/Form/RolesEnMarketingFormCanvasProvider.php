<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\RolesEnMarketing;

class RolesEnMarketingFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnMarketing::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var RolesEnMarketing $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouveau Rôle en Marketing",
            "titre_modification" => "Modification du Rôle #%id%",
            "endpoint_submit_url" => "/admin/rolesenmarketing/api/submit",
            "endpoint_delete_url" => "/admin/rolesenmarketing/api/delete",
            "endpoint_form_url" => "/admin/rolesenmarketing/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildRolesEnMarketingLayout();

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildRolesEnMarketingLayout(): array
    {
        return [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["invite"]]]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessPiste"]], 
                ["champs" => ["accessTache"]], 
                ["champs" => ["accessFeedback"]]
            ]],
        ];
    }
}