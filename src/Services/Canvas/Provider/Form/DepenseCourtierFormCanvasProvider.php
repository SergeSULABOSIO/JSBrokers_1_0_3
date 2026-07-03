<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\DepenseCourtier;

class DepenseCourtierFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === DepenseCourtier::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var DepenseCourtier $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouvelle Dépense",
            "titre_modification" => "Modification de la Dépense #%id%",
            "endpoint_submit_url" => "/admin/depensecourtier/api/submit",
            "endpoint_delete_url" => "/admin/depensecourtier/api/delete",
            "endpoint_form_url" => "/admin/depensecourtier/api/get-form",
            "isCreationMode" => $isParentNew,
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Dépense du cabinet",
                "description" => "Vous enregistrez une sortie de fonds de votre cabinet, classée par type de charge. « Engagée » : la charge pèse sur le résultat sans décaisser ; « Payée » : elle décaisse la trésorerie (banque ou caisse) ; « Annulée » : elle est exclue de la comptabilité. La TVA déductible alimente votre suivi fiscal.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "charge"        => "charge",
                "dateDepense"   => "action:calendar",
                "montant"       => "monnaie",
                "tauxTva"       => "taxe",
                "beneficiaire"  => "partenaire",
                "reference"     => "document",
                "moyenPaiement" => "compte-bancaire",
                "statut"        => "action:filter",
                "description"   => "action:description",
            ],
        ];
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["charge"], 'width' => 8], ["champs" => ["dateDepense"], 'width' => 4]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["montant"], 'width' => 6], ["champs" => ["tauxTva"], 'width' => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["moyenPaiement"], 'width' => 6], ["champs" => ["statut"], 'width' => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["beneficiaire"], 'width' => 6], ["champs" => ["reference"], 'width' => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"], 'width' => 12]]],
        ];

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }
}
