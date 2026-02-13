<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Paiement;

class PaiementFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Paiement::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Paiement $object */
        $isParentNew = ($object->getId() === null);
        $paiementId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Paiement",
            "titre_modification" => "Modification du paiement #%id%",
            "endpoint_submit_url" => "/admin/paiement/api/submit",
            "endpoint_delete_url" => "/admin/paiement/api/delete",
            "endpoint_form_url" => "/admin/paiement/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildPaiementLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildPaiementLayout(Paiement $object, bool $isParentNew): array
    {
        $paiementId = $object->getId() ?? 0;
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["montant"]], ["champs" => ["reference"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["paidAt"]], ["champs" => ["CompteBancaire"]]]],
        ];

        $collections = [
            ['fieldName' => 'preuves', 'entityRouteName' => 'document', 'formTitle' => 'Preuve', 'parentFieldName' => 'paiement'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}