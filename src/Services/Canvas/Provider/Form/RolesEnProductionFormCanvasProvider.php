<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\RolesEnProduction;

class RolesEnProductionFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnProduction::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var RolesEnProduction $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouveau Rôle en Production",
            "titre_modification" => "Modification du Rôle #%id%",
            "endpoint_submit_url" => "/admin/rolesenproduction/api/submit",
            "endpoint_delete_url" => "/admin/rolesenproduction/api/delete",
            "endpoint_form_url" => "/admin/rolesenproduction/api/get-form",
            "isCreationMode" => $isParentNew,
            // Rendu dédié « droits d'accès » (grille de cases sur charte cobalt).
            "form_class" => "form-column--roles",
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Droits d'accès — module Production",
                "description" => "Vous définissez ce que ce collaborateur peut consulter et modifier sur le portefeuille de l'entreprise (groupes, clients, portefeuilles, assureurs, contacts, risques, avenants, partenaires, cotations). Ces droits s'appliquent dès l'enregistrement : n'accordez que le nécessaire.",
                // Libellés des puces de contexte (rappel des champs masqués pré-remplis).
                "facts_labels" => [
                    "nom"    => "Libellé du rôle",
                    "invite" => "Collaborateur concerné",
                ],
            ],
            // Mini-pastille par carte de droits : icône de l'entité concernée (alias IconCanvasProvider).
            "field_icons" => [
                "accessGroupe"     => "groupe",
                "accessClient"     => "client",
                "accessPortefeuille" => "portefeuille",
                "accessAssureur"   => "assureur",
                "accessContact"    => "contact",
                "accessRisque"     => "risque",
                "accessAvenant"    => "avenant",
                "accessPartenaire" => "partenaire",
                "accessCotation"   => "cotation",
            ],
        ];
        $layout = $this->buildRolesEnProductionLayout();

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildRolesEnProductionLayout(): array
    {
        return [
            // Champs connus et pré-remplis (libellé du rôle + collaborateur cible) :
            // rendus masqués (soumis mais non affichés) pour alléger le formulaire.
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["invite"]]]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessGroupe"]],
                ["champs" => ["accessClient"]],
                ["champs" => ["accessPortefeuille"]]
            ]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessAssureur"]],
                ["champs" => ["accessContact"]],
                ["champs" => ["accessRisque"]]
            ]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessAvenant"]],
                ["champs" => ["accessPartenaire"]],
                ["champs" => ["accessCotation"]]
            ]],
        ];
    }
}