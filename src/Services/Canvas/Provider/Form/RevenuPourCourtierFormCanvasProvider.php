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
            // Colonne gauche du dialogue EN CRÉATION : ce que cet objet apporte,
            // et ce qu'il engage en aval. Le premier paragraphe se suffit à
            // lui-même — c'est lui qui répond à qui ouvre le dialogue sans savoir.
            "description_creation" => [
                "Un revenu est ce que l'affaire vous rapporte : sans lui, une cotation décrit une prime que vous placez sans rien facturer.",
                "Il s'appuie sur un type de revenu, qui dit comment il se calcule et qui le doit — l'assureur pour une commission, le client pour des honoraires. Un montant ou un taux exceptionnel peut y déroger pour cette affaire seulement.",
                "C'est ce revenu qui devient facturable, puis encaissable, puis partageable avec les intermédiaires. Le chiffre d'affaires du cabinet se compte sur les commissions réellement encaissées.",
            ],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Revenu pour courtier",
                "description" => "Vous rattachez un revenu du courtier à une affaire en vous appuyant sur un type de revenu, avec la possibilité d'un montant ou d'un taux exceptionnel. Il détermine la rémunération facturable sur l'affaire concernée.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "documents" => "document",
                "nom"                    => "action:edit",
                "typeRevenu"             => "type-revenu",
                "montantFlatExceptionel" => "action:count",
                "tauxExceptionel"        => "action:count",
            ],
        ];
        $layout = $this->buildRevenuPourCourtierLayout($revenuId, $isParentNew);

        // Pièces jointes de cette fiche.
        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'revenuPourCourtier'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

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