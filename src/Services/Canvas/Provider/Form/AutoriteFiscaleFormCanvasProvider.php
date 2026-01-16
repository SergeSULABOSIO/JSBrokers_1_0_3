<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\AutoriteFiscale;

class AutoriteFiscaleFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === AutoriteFiscale::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var AutoriteFiscale $object */
        $isParentNew = ($object->getId() === null);
        $autoriteId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Autorité Fiscale",
            "titre_modification" => "Modification de l'Autorité Fiscale #%id%",
            "endpoint_submit_url" => "/admin/autoritefiscale/api/submit",
            "endpoint_delete_url" => "/admin/autoritefiscale/api/delete",
            "endpoint_form_url" => "/admin/autoritefiscale/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildAutoriteFiscaleLayout($autoriteId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildAutoriteFiscaleLayout(int $autoriteId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["abreviation"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["taxe"]]]],
        ];
        return $layout;
    }
}