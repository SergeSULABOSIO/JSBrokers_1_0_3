<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\PeriodeBlocage;

class PeriodeBlocageFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === PeriodeBlocage::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var PeriodeBlocage $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouvelle période de blocage",
            "titre_modification" => "Période de blocage #%id%",
            "endpoint_submit_url" => "/admin/periodeblocage/api/submit",
            "endpoint_delete_url" => "/admin/periodeblocage/api/delete",
            "endpoint_form_url" => "/admin/periodeblocage/api/get-form",
            "isCreationMode" => $isParentNew,
            "form_intro" => [
                "titre" => "Période sans congé",
                "description" => "Clôture d'exercice, campagne de renouvellement : un moment où le cabinet a besoin de tout le monde. Une demande qui y tombe est refusée — sauf à un valideur, qui peut passer outre, le contournement étant alors consigné et signalé.",
            ],
            "suppression_note" => "Décochez « Période active » plutôt que de supprimer : une période passée explique des refus passés, et l'effacer les rend incompréhensibles.",
            "field_icons" => [
                "libelle"   => "action:description",
                "dateDebut" => "action:calendar",
                "dateFin"   => "action:calendar",
                "actif"     => "action:check",
            ],
        ];

        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["libelle"], 'width' => 12]]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["dateDebut"], 'width' => 4],
                ["champs" => ["dateFin"], 'width' => 4],
                ["champs" => ["actif"], 'width' => 4],
            ]],
        ];

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout),
        ];
    }
}
