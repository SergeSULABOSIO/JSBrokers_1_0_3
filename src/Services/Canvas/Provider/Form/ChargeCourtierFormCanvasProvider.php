<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\ChargeCourtier;

class ChargeCourtierFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ChargeCourtier::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var ChargeCourtier $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouvelle Charge",
            "titre_modification" => "Modification de la Charge #%id%",
            "endpoint_submit_url" => "/admin/chargecourtier/api/submit",
            "endpoint_delete_url" => "/admin/chargecourtier/api/delete",
            "endpoint_form_url" => "/admin/chargecourtier/api/get-form",
            "isCreationMode" => $isParentNew,
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Type de charge (OHADA)",
                "description" => "Vous définissez une catégorie de charge de votre cabinet, rattachée à un compte de la classe 6 du plan comptable SYSCOHADA. Elle classe vos dépenses réelles et détermine leur poste au compte de résultat de vos documents comptables.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "code"                  => "action:edit",
                "libelle"               => "action:description",
                "compteOhada"           => "charge",
                "comportement"          => "action:options",
                "periodicite"           => "action:calendar",
                "montantBudgeteMensuel" => "monnaie",
                "actif"                 => "action:check",
            ],
        ];
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["code"], 'width' => 4], ["champs" => ["libelle"], 'width' => 8]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["compteOhada"], 'width' => 12]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["comportement"], 'width' => 6], ["champs" => ["periodicite"], 'width' => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["montantBudgeteMensuel"], 'width' => 6], ["champs" => ["actif"], 'width' => 6]]],
        ];

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }
}
