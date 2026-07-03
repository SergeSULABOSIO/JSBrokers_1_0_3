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
            "isCreationMode" => $isParentNew,
            // Rendu dédié « droits d'accès » (grille de cases sur charte cobalt).
            "form_class" => "form-column--roles",
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Droits d'accès — module Finance",
                "description" => "Vous définissez ce que ce collaborateur peut consulter et modifier sur les données financières de l'entreprise (monnaies, comptes bancaires, taxes, paiements, bordereaux, revenus, charges, dépenses, documents comptables…). Ces droits s'appliquent dès l'enregistrement : n'accordez que le nécessaire.",
                // Libellés des puces de contexte (rappel des champs masqués pré-remplis).
                "facts_labels" => [
                    "nom"    => "Libellé du rôle",
                    "invite" => "Collaborateur concerné",
                ],
            ],
            // Mini-pastille par carte de droits : icône de l'entité concernée (alias IconCanvasProvider).
            "field_icons" => [
                "accessMonnaie"        => "monnaie",
                "accessCompteBancaire" => "compte-bancaire",
                "accessTaxe"           => "taxe",
                "accessTypeRevenu"     => "type-revenu",
                "accessTranche"        => "tranche",
                "accessTypeChargement" => "chargement",
                "accessNote"           => "note",
                "accessPaiement"       => "paiement",
                "accessBordereau"      => "bordereau",
                "accessRevenu"         => "revenu",
                "accessCharge"         => "charge",
                "accessDepense"        => "depense",
                "accessDocumentComptable" => "document-comptable",
            ],
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
            // Champs connus et pré-remplis (libellé du rôle + collaborateur cible) :
            // rendus masqués (soumis mais non affichés) pour alléger le formulaire.
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["invite"]]]],
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
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessRevenu"]],
                ["champs" => ["accessCharge"]],
                ["champs" => ["accessDepense"]]
            ]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["accessDocumentComptable"]]]],
        ];
    }
}