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
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["email"]], ["champs" => ["telephone"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["adressePhysique"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["numimpot"]], ["champs" => ["idnat"]], ["champs" => ["rccm"]]]],
        ];

        $collections = [
            ['fieldName' => 'cotations', 'entityRouteName' => 'cotation', 'formTitle' => 'Cotation', 'parentFieldName' => 'assureur'],
            ['fieldName' => 'bordereaus', 'entityRouteName' => 'bordereau', 'formTitle' => 'Bordereau', 'parentFieldName' => 'assureur'],
            ['fieldName' => 'notificationSinistres', 'entityRouteName' => 'notificationsinistre', 'formTitle' => 'Sinistre', 'parentFieldName' => 'assureur'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $assureurId, $isParentNew, $collections);
        return $layout;
    }
}
