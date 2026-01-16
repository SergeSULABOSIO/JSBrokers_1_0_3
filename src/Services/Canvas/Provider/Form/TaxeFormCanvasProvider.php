<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Taxe;

class TaxeFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Taxe::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Taxe $object */
        $isParentNew = ($object->getId() === null);
        $taxeId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Taxe",
            "titre_modification" => "Modification de la Taxe #%id%",
            "endpoint_submit_url" => "/admin/taxe/api/submit",
            "endpoint_delete_url" => "/admin/taxe/api/delete",
            "endpoint_form_url" => "/admin/taxe/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildTaxeLayout($taxeId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildTaxeLayout(int $taxeId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["code"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["tauxIARD"]], ["champs" => ["tauxVIE"]]]],
        ];
        return $layout;
    }
}
