<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Taxe;

class TaxeFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Taxe::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Taxe $object */
        $isParentNew = ($object->getId() === null);
        $taxeId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Taxe",
            "titre_modification" => "Modification de la Taxe #%id%",
            "endpoint_submit_url" => "/admin/taxe/api/submit",
            "endpoint_delete_url" => "/admin/taxe/api/delete",
            "endpoint_form_url" => "/admin/taxe/api/get-form",
            "isCreationMode" => $isParentNew,
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Taxe",
                "description" => "Vous définissez une taxe applicable aux primes d'assurance : ses taux IARD et Vie, son redevable et les autorités fiscales bénéficiaires. Elle est appliquée automatiquement lors des calculs sur les cotations.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "code"             => "action:edit",
                "tauxIARD"         => "action:count",
                "tauxVIE"          => "action:count",
                "description"      => "action:description",
                "redevable"        => "action:options",
                "autoriteFiscales" => "autorite-fiscale",
            ],
        ];
        $layout = $this->buildTaxeLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildTaxeLayout(Taxe $object, bool $isParentNew): array
    {
        $layout = [
            [
                "couleur_fond" => "white",
                "colonnes" => [["champs" => ["code"], 'width' => 6], ["champs" => ["tauxIARD"], 'width' => 3], ["champs" => ["tauxVIE"], 'width' => 3]]
            ],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"], 'width' => 12]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["redevable"], 'width' => 12]]],
        ];

        $collections = [
            ['fieldName' => 'autoriteFiscales', 'entityRouteName' => 'autoritefiscale', 'formTitle' => 'Autorité Fiscale', 'parentFieldName' => 'taxe'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return $layout;
    }
}
