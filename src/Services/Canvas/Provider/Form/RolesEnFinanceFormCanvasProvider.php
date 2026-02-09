<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\RolesEnFinance;

class RolesEnFinanceFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnFinance::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var RolesEnFinance $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouveau Rôle en Finance",
            "titre_modification" => "Modification du Rôle #%id%",
            "endpoint_submit_url" => "/admin/rolesenfinance/api/submit",
            "endpoint_delete_url" => "/admin/rolesenfinance/api/delete",
            "endpoint_form_url" => "/admin/rolesenfinance/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildRolesEnFinanceLayout();

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildRolesEnFinanceLayout(): array
    {
        return [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["invite"]]]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessMonnaie"]], 
                ["champs" => ["accessCompteBancaire"]], 
                ["champs" => ["accessTaxe"]]
            ]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessTypeRevenu"]], 
                ["champs" => ["accessTranche"]], 
                ["champs" => ["accessTypeChargement"]]
            ]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessNote"]], 
                ["champs" => ["accessPaiement"]], 
                ["champs" => ["accessBordereau"]]
            ]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["accessRevenu"]]]],
        ];
    }
}