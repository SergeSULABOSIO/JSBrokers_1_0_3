<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\RolesEnAdministration;

class RolesEnAdministrationFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnAdministration::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var RolesEnAdministration $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouveau Rôle en Administration",
            "titre_modification" => "Modification du Rôle #%id%",
            "endpoint_submit_url" => "/admin/rolesenadministration/api/submit",
            "endpoint_delete_url" => "/admin/rolesenadministration/api/delete",
            "endpoint_form_url" => "/admin/rolesenadministration/api/get-form",
            "isCreationMode" => $isParentNew,
            // Colonne gauche du dialogue EN CRÉATION : ce que cet objet apporte,
            // et ce qu'il engage en aval. Le premier paragraphe se suffit à
            // lui-même — c'est lui qui répond à qui ouvre le dialogue sans savoir.
            "description_creation" => [
                "Un rôle ouvre une porte : sans lui, ce collaborateur ne voit rien du module Administration, car l'espace de travail est fermé par défaut.",
                "Vous décidez ici ce qu'il peut consulter et modifier sur les documents, classeurs, collaborateurs invités, assistant IA, congés et échange de données.",
                "Deux droits engagent plus que les autres : l'importation / exportation est la seule rubrique par laquelle les données SORTENT du cabinet, et le paramétrage des congés fixe des règles qui valent pour tout le monde.",
                "Les droits s'appliquent dès l'enregistrement. N'accordez que le nécessaire.",
            ],
            // Rendu dédié « droits d'accès » (grille de cases sur charte cobalt).
            "form_class" => "form-column--roles",
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Droits d'accès — module Administration",
                "description" => "Vous définissez ce que ce collaborateur peut consulter et modifier sur l'administration de l'espace de travail (documents, classeurs, collaborateurs invités, assistant IA, congés, importation / exportation). Ces droits s'appliquent dès l'enregistrement : n'accordez que le nécessaire.",
                // Libellés des puces de contexte (rappel des champs masqués pré-remplis).
                "facts_labels" => [
                    "nom"    => "Libellé du rôle",
                    "invite" => "Collaborateur concerné",
                ],
            ],
            // Mini-pastille par carte de droits : icône de l'entité concernée (alias IconCanvasProvider).
            "field_icons" => [
                "accessDocument"    => "document",
                "accessClasseur"    => "classeur",
                "accessInvite"      => "invite",
                "accessAssistantIa" => "assistant-ia",
                "accessConge"       => "conge",
                "accessCongeParametre" => "type-absence",
                "accessEchange"     => "echange",
            ],
        ];
        $layout = $this->buildRolesEnAdministrationLayout();

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildRolesEnAdministrationLayout(): array
    {
        return [
            // Champs connus et pré-remplis (libellé du rôle + collaborateur cible) :
            // rendus masqués (soumis mais non affichés) pour alléger le formulaire.
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["invite"]]]],
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessDocument"]],
                ["champs" => ["accessClasseur"]],
                ["champs" => ["accessInvite"]],
                ["champs" => ["accessAssistantIa"]]
            ]],
            // Les congés forment leur propre rangée : leurs deux droits se lisent
            // ensemble (« qui valide » / « qui paramètre »), et la rangée précédente
            // affiche déjà quatre cartes — au-delà, la grille se casse.
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessConge"]],
                ["champs" => ["accessCongeParametre"]]
            ]],
            // L'échange de données forme sa propre rangée : c'est la seule capacité du
            // module qui fasse SORTIR les données du cabinet, et la seule dont l'aide
            // ait besoin d'être lue en entier avant qu'on ne coche quoi que ce soit.
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["accessEchange"]]
            ]],
        ];
    }
}