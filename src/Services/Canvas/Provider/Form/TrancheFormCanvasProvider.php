<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Tranche;

class TrancheFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tranche::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Tranche $object */
        $isParentNew = ($object->getId() === null);
        $trancheId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Tranche",
            "titre_modification" => "Modification de la Tranche #%id%",
            "endpoint_submit_url" => "/admin/tranche/api/submit",
            "endpoint_delete_url" => "/admin/tranche/api/delete",
            "endpoint_form_url" => "/admin/tranche/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildTrancheLayout($trancheId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildTrancheLayout(int $trancheId, bool $isParentNew): array
    {
        // Conditions de visibilité pour les champs dynamiques
        $visibilityConditionPourcentage = [
            'visibility_conditions' => [
                ['field' => 'modeCalcul', 'operator' => 'in', 'value' => ['pourcentage']]
            ]
        ];
        $visibilityConditionMontant = [
            'visibility_conditions' => [
                ['field' => 'modeCalcul', 'operator' => 'in', 'value' => ['montant_fixe']]
            ]
        ];

        $layout = [
            // Ligne 1: Nom (Toute la largeur)
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            
            // Ligne 3: Montant Fixe (Visible si modeCalcul == montant_fixe)
            ["couleur_fond" => "white", "colonnes" => [["champs" => [array_merge(['field_code' => 'montantFlat'], $visibilityConditionMontant)]]]],
            
            // Ligne 4: Pourcentage (Visible si modeCalcul == pourcentage)
            ["couleur_fond" => "white", "colonnes" => [["champs" => [array_merge(['field_code' => 'pourcentage'], $visibilityConditionPourcentage)]]]],
            
            // Ligne 5: PayableAt et EcheanceAt (6/12 chacun)
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["payableAt"], "width" => 6],
                ["champs" => ["echeanceAt"], "width" => 6]
            ]],
        ];
        return $layout;
    }
}
