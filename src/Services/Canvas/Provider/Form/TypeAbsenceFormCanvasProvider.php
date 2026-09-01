<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\TypeAbsence;

class TypeAbsenceFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === TypeAbsence::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var TypeAbsence $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouveau type d'absence",
            "titre_modification" => "Modification du type d'absence #%id%",
            "endpoint_submit_url" => "/admin/typeabsence/api/submit",
            "endpoint_delete_url" => "/admin/typeabsence/api/delete",
            "endpoint_form_url" => "/admin/typeabsence/api/get-form",
            "isCreationMode" => $isParentNew,
            "form_intro" => [
                "titre" => "Type d'absence",
                "description" => "Vous décrivez une nature d'absence que vos collaborateurs pourront poser. La case « Décompté du solde » est celle qui compte : elle seule fait qu'une demande approuvée retire des jours au compteur. Une maladie ou un événement familial se déclarent sans y toucher.",
            ],
            "suppression_note" => "Un type déjà utilisé par une demande ou un mouvement ne se supprime pas : décochez « Actif » pour le retirer de la saisie. L'historique doit rester lisible.",
            "field_icons" => [
                "code"                => "action:edit",
                "libelle"             => "type-absence",
                "decompte"            => "action:count",
                "justificatifRequis"  => "document",
                "plafondParDemande"   => "action:alert",
                "autoriseDemiJournee" => "action:calendar",
                "couleur"             => "action:image",
                "actif"               => "action:check",
            ],
        ];

        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["code"], 'width' => 4], ["champs" => ["libelle"], 'width' => 8]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["decompte"], 'width' => 6], ["champs" => ["justificatifRequis"], 'width' => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["plafondParDemande"], 'width' => 6], ["champs" => ["autoriseDemiJournee"], 'width' => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["couleur"], 'width' => 6], ["champs" => ["actif"], 'width' => 6]]],
        ];

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout),
        ];
    }
}
