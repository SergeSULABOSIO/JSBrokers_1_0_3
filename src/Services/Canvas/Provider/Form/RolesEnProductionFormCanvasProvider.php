<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\RolesEnProduction;

class RolesEnProductionFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnProduction::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var RolesEnProduction $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouveau Rôle en Production",
            "titre_modification" => "Modification du Rôle #%id%",
            "endpoint_submit_url" => "/admin/rolesenproduction/api/submit",
            "endpoint_delete_url" => "/admin/rolesenproduction/api/delete",
            "endpoint_form_url" => "/admin/rolesenproduction/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildRolesEnProductionLayout();

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildRolesEnProductionLayout(): array
    {
        return [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["invite"]]]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessGroupe"]], 
                ["champs" => ["accessClient"]], 
                ["champs" => ["accessAssureur"]]
            ]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessContact"]], 
                ["champs" => ["accessRisque"]], 
                ["champs" => ["accessAvenant"]]
            ]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["accessPartenaire"]], ["champs" => ["accessCotation"]]]],
        ];
    }
}