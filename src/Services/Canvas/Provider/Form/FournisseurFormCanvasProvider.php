<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Fournisseur;

class FournisseurFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Fournisseur::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Fournisseur $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouveau Fournisseur",
            "titre_modification" => "Modification du Fournisseur #%id%",
            "endpoint_submit_url" => "/admin/fournisseur/api/submit",
            "endpoint_delete_url" => "/admin/fournisseur/api/delete",
            "endpoint_form_url" => "/admin/fournisseur/api/get-form",
            "isCreationMode" => $isParentNew,
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Fournisseur professionnel",
                "description" => "Vous enregistrez un opérateur économique auprès duquel votre cabinet s'approvisionne (internet, consommables, courrier…). Vos dépenses pourront lui être rattachées, et son dossier (contrat, agrément, preuves de partenariat) est conservé en pièces jointes.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "nom"             => "fournisseur",
                "personneContact" => "contact",
                "telephone"       => "contact",
                "email"           => "invite",
                "adresse"         => "entreprise",
                "rccm"            => "action:edit",
                "numimpot"        => "taxe",
                "description"     => "action:description",
                "actif"           => "action:check",
                "documents"       => "document",
            ],
        ];
        $layout = $this->buildFournisseurLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildFournisseurLayout(Fournisseur $object, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"], 'width' => 8], ["champs" => ["actif"], 'width' => 4]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["personneContact"], 'width' => 6], ["champs" => ["telephone"], 'width' => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["email"], 'width' => 6], ["champs" => ["adresse"], 'width' => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["rccm"], 'width' => 6], ["champs" => ["numimpot"], 'width' => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"], 'width' => 12]]],
        ];

        // Dossier fournisseur : contrats, agréments, preuves de partenariat…
        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'fournisseur'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return $layout;
    }
}
