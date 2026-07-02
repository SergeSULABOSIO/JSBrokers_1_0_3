<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\RolesEnMarketing;

class RolesEnMarketingFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnMarketing::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var RolesEnMarketing $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouveau Rôle en Marketing",
            "titre_modification" => "Modification du Rôle #%id%",
            "endpoint_submit_url" => "/admin/rolesenmarketing/api/submit",
            "endpoint_delete_url" => "/admin/rolesenmarketing/api/delete",
            "endpoint_form_url" => "/admin/rolesenmarketing/api/get-form",
            "isCreationMode" => $isParentNew,
            // Rendu dédié « droits d'accès » (grille de cases sur charte cobalt).
            "form_class" => "form-column--roles",
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Droits d'accès — module Marketing",
                "description" => "Vous définissez ce que ce collaborateur peut consulter et modifier sur l'activité commerciale de l'entreprise (pistes, tâches, feedbacks). Ces droits s'appliquent dès l'enregistrement : n'accordez que le nécessaire.",
                // Libellés des puces de contexte (rappel des champs masqués pré-remplis).
                "facts_labels" => [
                    "nom"    => "Libellé du rôle",
                    "invite" => "Collaborateur concerné",
                ],
            ],
            // Mini-pastille par carte de droits : icône de l'entité concernée (alias IconCanvasProvider).
            "field_icons" => [
                "accessPiste"    => "piste",
                "accessTache"    => "tache",
                "accessFeedback" => "feedback",
            ],
        ];
        $layout = $this->buildRolesEnMarketingLayout();

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildRolesEnMarketingLayout(): array
    {
        return [
            // Champs connus et pré-remplis (libellé du rôle + collaborateur cible) :
            // rendus masqués (soumis mais non affichés) pour alléger le formulaire.
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["invite"]]]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessPiste"]], 
                ["champs" => ["accessTache"]], 
                ["champs" => ["accessFeedback"]]
            ]],
        ];
    }
}