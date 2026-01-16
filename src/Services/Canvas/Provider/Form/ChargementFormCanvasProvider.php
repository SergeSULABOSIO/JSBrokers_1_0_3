<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Chargement;

class ChargementFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Chargement::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Chargement $object */
        $isParentNew = ($object->getId() === null);
        $chargementId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Type de Chargement",
            "titre_modification" => "Modification du Type de Chargement #%id%",
            "endpoint_submit_url" => "/admin/chargement/api/submit",
            "endpoint_delete_url" => "/admin/chargement/api/delete",
            "endpoint_form_url" => "/admin/chargement/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildChargementLayout($chargementId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildChargementLayout(int $chargementId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["fonction"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
        ];
        return $layout;
    }
}