<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Entreprise;

class EntrepriseFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Entreprise::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Entreprise $object */
        $isParentNew = ($object->getId() === null);
        $entrepriseId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Entreprise",
            "titre_modification" => "Modification de l'Entreprise #%id%",
            "endpoint_submit_url" => "/admin/entreprise/api/submit",
            "endpoint_delete_url" => "/admin/entreprise/api/delete",
            "endpoint_form_url" => "/admin/entreprise/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildEntrepriseLayout($entrepriseId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildEntrepriseLayout(int $entrepriseId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["licence"]]]],
        ];
        return $layout;
    }
}