<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Assureur;

class AssureurFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Assureur::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Assureur $object */
        $isParentNew = ($object->getId() === null);
        $assureurId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvel Assureur",
            "titre_modification" => "Modification de l'Assureur #%id%",
            "endpoint_submit_url" => "/admin/assureur/api/submit",
            "endpoint_delete_url" => "/admin/assureur/api/delete",
            "endpoint_form_url" => "/admin/assureur/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildAssureurLayout($assureurId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildAssureurLayout(int $assureurId, bool $isParentNew): array
    {
        $layout = [
            // Ligne 1: le nom
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            // Ligne 2: l'email (1/2 de la largeur) et l'adresse (1/2)
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["email"], "width" => 6],
                ["champs" => ["adressePhysique"], "width" => 6]
            ]],
            // ligne n 3: le num impot, l'id nationale et le rccm.
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["numimpot"]], ["champs" => ["idnat"]], ["champs" => ["rccm"]]]],
        ];

        return $layout;
    }
}
