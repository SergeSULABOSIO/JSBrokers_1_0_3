<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Partenaire;

class PartenaireFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Partenaire::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Partenaire $object */
        $isParentNew = ($object->getId() === null);
        $partenaireId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Partenaire",
            "titre_modification" => "Modification du Partenaire #%id%",
            "endpoint_submit_url" => "/admin/partenaire/api/submit",
            "endpoint_delete_url" => "/admin/partenaire/api/delete",
            "endpoint_form_url" => "/admin/partenaire/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildPartenaireLayout($partenaireId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildPartenaireLayout(int $partenaireId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["email"]], ["champs" => ["telephone"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["part"]]]],
        ];
        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'partenaire'],
            ['fieldName' => 'clients', 'entityRouteName' => 'client', 'formTitle' => 'Client', 'parentFieldName' => 'partenaires'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $partenaireId, $isParentNew, $collections);
        return $layout;
    }
}
