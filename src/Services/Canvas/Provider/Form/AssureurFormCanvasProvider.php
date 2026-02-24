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
            // Ligne 2: le téléphone
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["telephone"]]]],
            // Ligne 2: adressePhysique
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["adressePhysique"]]]],
            // Ligne 3: url (1/2) et email (1/2)
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["url"], "width" => 6],
                ["champs" => ["email"], "width" => 6]
            ]],
            // Ligne 4: numimpot (1/3), rccm (1/3) et idnat (1/3)
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["numimpot"], "width" => 4],
                ["champs" => ["rccm"], "width" => 4],
                ["champs" => ["idnat"], "width" => 4]
            ]],
        ];

        return $layout;
    }
}
