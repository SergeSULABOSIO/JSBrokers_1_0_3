<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\RevenuPourCourtier;

class RevenuPourCourtierFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RevenuPourCourtier::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var RevenuPourCourtier $object */
        $isParentNew = ($object->getId() === null);
        $revenuId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Revenu pour Courtier",
            "titre_modification" => "Modification du Revenu #%id%",
            "endpoint_submit_url" => "/admin/revenupourcourtier/api/submit",
            "endpoint_delete_url" => "/admin/revenupourcourtier/api/delete",
            "endpoint_form_url" => "/admin/revenupourcourtier/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildRevenuPourCourtierLayout($revenuId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildRevenuPourCourtierLayout(int $revenuId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["cotation"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["typeRevenu"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["montantFlatExceptionel"]], ["champs" => ["tauxExceptionel"]]]],
        ];
        return $layout;
    }
}