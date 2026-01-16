<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\ChargementPourPrime;

class ChargementPourPrimeFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ChargementPourPrime::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var ChargementPourPrime $object */
        $isParentNew = ($object->getId() === null);
        $chargementId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Chargement sur Prime",
            "titre_modification" => "Modification du Chargement #%id%",
            "endpoint_submit_url" => "/admin/chargementpourprime/api/submit",
            "endpoint_delete_url" => "/admin/chargementpourprime/api/delete",
            "endpoint_form_url" => "/admin/chargementpourprime/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildChargementPourPrimeLayout($chargementId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildChargementPourPrimeLayout(int $chargementId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["cotation"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["type"]], ["champs" => ["montantFlatExceptionel"]]]],
        ];
        return $layout;
    }
}