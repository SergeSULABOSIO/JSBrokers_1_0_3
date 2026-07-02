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
            "isCreationMode" => $isParentNew,
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Revenu pour courtier",
                "description" => "Vous rattachez un revenu du courtier à une affaire en vous appuyant sur un type de revenu, avec la possibilité d'un montant ou d'un taux exceptionnel. Il détermine la rémunération facturable sur l'affaire concernée.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "nom"                    => "action:edit",
                "typeRevenu"             => "type-revenu",
                "montantFlatExceptionel" => "action:count",
                "tauxExceptionel"        => "action:count",
            ],
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
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["typeRevenu"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["montantFlatExceptionel"], "width" => 6], ["champs" => ["tauxExceptionel"], "width" => 6]]],
        ];
        return $layout;
    }
}