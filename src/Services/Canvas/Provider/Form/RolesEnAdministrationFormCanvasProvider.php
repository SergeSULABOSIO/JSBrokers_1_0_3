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
            "isCreationMode" => $isParentNew,
            // Rendu dédié « droits d'accès » (grille de cases sur charte cobalt).
            "form_class" => "form-column--roles",
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
            // Champs connus et pré-remplis (libellé du rôle + collaborateur cible) :
            // rendus masqués (soumis mais non affichés) pour alléger le formulaire.
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["invite"]]]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessDocument"]], 
                ["champs" => ["accessClasseur"]], 
                ["champs" => ["accessInvite"]]
            ]],
        ];
    }
}