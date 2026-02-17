<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Tranche;

class TrancheFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tranche::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Tranche $object */
        $isParentNew = ($object->getId() === null);
        $trancheId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Tranche",
            "titre_modification" => "Modification de la Tranche #%id%",
            "endpoint_submit_url" => "/admin/tranche/api/submit",
            "endpoint_delete_url" => "/admin/tranche/api/delete",
            "endpoint_form_url" => "/admin/tranche/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildTrancheLayout($trancheId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildTrancheLayout(int $trancheId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["cotation"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["montantFlat"]], ["champs" => ["pourcentage"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["payableAt"]]]],
        ];
        return $layout;
    }
}
