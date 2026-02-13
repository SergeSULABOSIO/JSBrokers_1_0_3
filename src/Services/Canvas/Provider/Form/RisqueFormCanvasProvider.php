<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Risque;

class RisqueFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Risque::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Risque $object */
        $isParentNew = ($object->getId() === null);
        $risqueId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Risque",
            "titre_modification" => "Modification du Risque #%id%",
            "endpoint_submit_url" => "/admin/risque/api/submit",
            "endpoint_delete_url" => "/admin/risque/api/delete",
            "endpoint_form_url" => "/admin/risque/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildRisqueLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildRisqueLayout(Risque $object, bool $isParentNew): array
    {
        $risqueId = $object->getId() ?? 0;
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nomComplet"]], ["champs" => ["code"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["branche"]], ["champs" => ["imposable"]]]],
        ];
        $collections = [
            ['fieldName' => 'pistes', 'entityRouteName' => 'piste', 'formTitle' => 'Piste', 'parentFieldName' => 'risque'],
            ['fieldName' => 'notificationSinistres', 'entityRouteName' => 'notificationsinistre', 'formTitle' => 'Sinistre', 'parentFieldName' => 'risque'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}
