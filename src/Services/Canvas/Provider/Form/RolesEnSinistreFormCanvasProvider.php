<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\RolesEnSinistre;

class RolesEnSinistreFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnSinistre::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var RolesEnSinistre $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouveau Rôle en Sinistre",
            "titre_modification" => "Modification du Rôle #%id%",
            "endpoint_submit_url" => "/admin/rolesensinistre/api/submit",
            "endpoint_delete_url" => "/admin/rolesensinistre/api/delete",
            "endpoint_form_url" => "/admin/rolesensinistre/api/get-form",
            "isCreationMode" => $isParentNew,
            // Rendu dédié « droits d'accès » (grille de cases sur charte cobalt).
            "form_class" => "form-column--roles",
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Droits d'accès — module Sinistre",
                "description" => "Vous définissez ce que ce collaborateur peut consulter et modifier sur la gestion des sinistres (types de pièces, notifications, règlements). Ces droits s'appliquent dès l'enregistrement : n'accordez que le nécessaire.",
            ],
        ];
        $layout = $this->buildLayout();

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildLayout(): array
    {
        return [
            // Champs connus et pré-remplis (libellé du rôle + collaborateur cible) :
            // rendus masqués (soumis mais non affichés) pour alléger le formulaire.
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["invite"]]]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessTypePiece"]],
                ["champs" => ["accessNotification"]],
                ["champs" => ["accessReglement"]],
            ]],
        ];
    }
}